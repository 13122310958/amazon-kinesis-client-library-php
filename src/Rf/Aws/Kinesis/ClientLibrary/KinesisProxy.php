<?php 

namespace Rf\Aws\Kinesis\ClientLibrary;

use Aws\Kinesis\KinesisClient;
use Rf\Aws\Kinesis\ClientLibrary\Entity\KinesisShard;
use Rf\Aws\Kinesis\ClientLibrary\Entity\KinesisDataRecord;
use Rf\Aws\Kinesis\ClientLibrary\KinesisShardDataStore;
use Rf\Aws\Kinesis\ClientLibrary\KinesisShardFileDataStore;

/**
 * AmazonKinesisのShard、DataRecordに関するラッパークラスです
 */
class KinesisProxy
{
  private static $instances = array();

  private $kinesis;

  private $stream_name;

  private $data_store;

  private $shard_hash;

  private $inited = false;

  private function __construct(KinesisClient $kinesis, KinesisShardDataStore $data_store, $stream_name, $init = true)
  {
    $this->kinesis = $kinesis;
    $this->data_store = $data_store;
    $this->stream_name = $stream_name;

    if ($init) {
      $this->initialize();
    }
  }

  public function initialize()
  {
    $this->shard_hash = $this->findWithMergeStoreShards();
    $this->inited = true;
  }

  public static function factory(KinesisClient $kinesis, KinesisShardDataStore $data_store, $stream_name, $init = true)
  {
    $key = sprintf("%s:%s:%s:%d", spl_object_hash($kinesis), get_class($data_store), $stream_name, $init);
    if (!isset(self::$instances[$key])) {
      $instance = new self($kinesis, $data_store, $stream_name, $init);
      self::$instances[$key] = $instance;
    } 

    return self::$instances[$key];
  }

  public function getKinesis()
  {
    return $this->kinesis;
  }

  public function getDataStore()
  {
    return $this->data_store;
  }

  public function findWithMergeStoreShards()
  {
      $data_store = $this->getDataStore();
      $shard_hash = $data_store->restore($this->stream_name);

      $new_shard_hash = $this->findOriginShards(empty($shard_hash) ? array() : array_keys($shard_hash));
      if (!empty($new_shard_hash)) {
        $shard_hash = array_merge($shard_hash, $new_shard_hash);
      }

      return $shard_hash;
  }

  public function findOriginShards($ignore_shard_ids = array())
  {
    $kinesis = $this->getKinesis();

    $result = array();
    while (true) {
        $describe_stream_result = $kinesis->describeStream(
          array(
            'StreamName' => $this->stream_name
          )
        );
        
        $stream_description = $describe_stream_result['StreamDescription'];
        $shards = $stream_description['Shards'];
        foreach ($shards as $shard) {
          $shard_id = $shard['ShardId'];
          if (in_array($shard_id, $ignore_shard_ids)) {
            continue;
          }

          $sequence_number_range = $shard['SequenceNumberRange'];
          $starting_sequence_number =  $sequence_number_range['StartingSequenceNumber'];
          
          $shard_obj = new KinesisShard();
          $shard_obj->setStreamName($this->stream_name);
          $shard_obj->setShardId($shard_id);
          $shard_obj->setSequenceNumber($starting_sequence_number);

          $result[$shard_id] = $shard_obj;
        }

        // HasMoreShardsでまだshardがあるかチェック
        $has_more_shards = $stream_description['HasMoreShards'];
        if (!$has_more_shards) {
          break;
        }
      }

      return $result;
  }

  public function findDataRecords($target_shard_id = null, $limit = 1000, $max_loop_count = 5, $parallel = false)
  {
    if (!$this->inited) {
      throw new Exception("Can not use initialize because not yet.");
    }

    if ($parallel && !extension_loaded('pthreads')) {
      throw new \RuntimeException('pthreads is required');
    }

    $result = array();
    foreach ($this->shard_hash as $shard_id => $shard) {
      if (!is_null($target_shard_id)) {
        if ($target_shard_id !== $shard_id) {
          continue;
        }
      }

      if ($parallel) {
        // TODO 未実装
      } else {
        $data_records = $this->_findDataRecords($shard, $limit, $max_loop_count);
      }

      if (!empty($data_records)) {
        $end_data_record = end($data_records);
        $shard->setSequenceNumber($end_data_record->getSequenceNumber());
      }

      $result = array_merge($result, $data_records);
    }

      return $result;
  }

  private function _findDataRecords(KinesisShard $shard, $limit, $max_loop_count)
  {
    $result = array();

    $option = null;
    if ($shard->getShardId() === '0') {
      $option = array('StreamName' => $shard->getStreamName(),
          'ShardIteratorType' => 'TRIM_HORIZON'
      );
    } else {
      $option = array('StreamName' => $shard->getStreamName(),
        'ShardId' => $shard->getShardId(),
        'ShardIteratorType' => 'AFTER_SEQUENCE_NUMBER',
        'StartingSequenceNumber' => $shard->getSequenceNumber()
      );
    }

    $kinesis = $this->getKinesis();

    $shard_iterator_result = $kinesis->getShardIterator($option);

    $shard_iterator = $shard_iterator_result['ShardIterator'];
    for ($i = 0; $i < $max_loop_count ; $i++) { 
        $get_records_result = $kinesis->getRecords(array(
            'ShardIterator' => $shard_iterator,
            'Limit' => $limit
        ));

        $records = $get_records_result['Records'];
        foreach ($records as $record) {
          $data_record = new KinesisDataRecord();
          $data_record->setStreamName($shard->getStreamName());
          $data_record->setShardId($shard->getShardId());
          $data_record->setSequenceNumber($record['SequenceNumber']);
          $data_record->setData($record['Data']);
          $data_record->setPartitionKey($record['PartitionKey']);

          $result[] = $data_record;
        }

        if (count($result)  >= $limit) {
            break;
        }

        $shard_iterator = $get_records_result['NextShardIterator'];
    }

    return $result;
  }

  public function checkpointAll()
  {
    foreach ($this->shard_hash as $shard_id => $shard) {
      $this->checkpoint($shard);
    }
  }

  public function checkpoint(KinesisShard $shard)
  {
    $data_store = $this->getDataStore();
    $data_store->modify($shard);
  }
}