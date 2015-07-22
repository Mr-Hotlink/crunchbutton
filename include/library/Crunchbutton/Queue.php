<?php

class Crunchbutton_Queue extends Cana_Table {

	const TYPE_CLASS_ORDER							= 'Crunchbutton_Queue_Order';
	const TYPE_CLASS_ORDER_RECEIPT					= 'Crunchbutton_Queue_Order_Receipt';
	const TYPE_CLASS_ORDER_CONFIRM					= 'Crunchbutton_Queue_Order_Confirm';
	const TYPE_CLASS_ORDER_PEXCARD_FUNDS			= 'Crunchbutton_Queue_Order_PexCard_Funds';
	const TYPE_CLASS_NOTIFICATION_DRIVER			= 'Crunchbutton_Queue_Driver';
	const TYPE_CLASS_NOTIFICATION_DRIVER_PRIORITY	= 'Crunchbutton_Queue_Notification_Driver_Priority';
	const TYPE_CLASS_NOTIFICATION_YOUR_DRIVER		= 'Crunchbutton_Queue_Notification_Your_Driver';
	const TYPE_CLASS_NOTIFICATION_MINUTES_WAY		= 'Crunchbutton_Queue_Notification_Minutes_Way';

	const TYPE_ORDER						= 'order';
	const TYPE_ORDER_RECEIPT				= 'order-receipt';
	const TYPE_ORDER_CONFIRM				= 'order-confirm';
	const TYPE_ORDER_PEXCARD_FUNDS			= 'order-pexcard-funds';
	const TYPE_NOTIFICATION_DRIVER			= 'notification-driver';
	const TYPE_NOTIFICATION_DRIVER_PRIORITY = 'notification-driver-priority';
	const TYPE_NOTIFICATION_YOUR_DRIVER		= 'notification-your-driver';
	const TYPE_NOTIFICATION_MINUTES_WAY		= 'notification-minutes-way';

	const STATUS_NEW		= 'new';
	const STATUS_SUCCESS	= 'success';
	const STATUS_FAILED		= 'failed';
	const STATUS_RUNNING	= 'running';
	const STATUS_STOPPED	= 'stopped';

	public static function process($all = false) {

		if (!$all) {
			$allQuery = ' and date_start<now()';
		}

		$queue = self::q('select * from queue where status=?'.$allQuery, [self::STATUS_NEW]);
		$queue = self::q('select * from queue where id_queue = 100473');
		foreach ($queue as $q) {
			echo 'Starting #'.$q->id_queue. '...';

			register_shutdown_function(function() use ($q) {
				$error = error_get_last();
				if ($error['type'] == E_ERROR) {
					$q->data = json_encode($error);
					$q->date_end = date('Y-m-d H:i:s');
					$q->status = self::STATUS_FAILED;
					$q->save();
				}
			});

			$q->status = self::STATUS_RUNNING;
			$q->save();

			$queue_type = $q->queue_type()->type;

			// Legacy
			if( !$queue_type && $q->type ){
				$queue_type = $q->type;
			}

			$type = 'TYPE_CLASS_'.str_replace('-','_',strtoupper($queue_type));
			$class = constant('self::'.$type);
			if (!$class) {
				$q->status = self::STATUS_FAILED;
				$q->date_end = date('Y-m-d H:i:s');
				$q->data = 'Invalid class type of: '.$queue_type;
				continue;
			}

			$q = new $class($q->properties());

			$res = $q->run();

			register_shutdown_function(function(){});

			if ($res !== false) {
				// not async
				$q->complete($res);
			}
		}
		return $queue->count();
	}

	public function queue_type(){
		if( !$this->_queue_type ){
			$this->_queue_type = Crunchbutton_Queue_Type::o( $this->id_queue_type );
		}
		return $this->_queue_type;
	}

	// dump the que and do nothing
	public static function clean() {
		c::db()->exec('update queue set status=?', [self::STATUS_STOPPED]);
	}

	// run the entire que until its empty
	public static function end() {
		self::process(true);
	}

	public function complete($status = self::STATUS_SUCCESS) {
		$this->status = $status;
		$this->data = null;
		$this->date_end = date('Y-m-d H:i:s');
		$this->save();
		echo $status."\n";
	}

	public function order() {
		return Order::o($this->id_order);
	}

	public function driver() {
		return Admin::o($this->id_admin);
	}

	public static function create($params = []) {
		if (!$params['date_start']) {
			$params['date_start'] = date('Y-m-d H:i:s');
		}

		$type = Crunchbutton_Queue_Type::byType( $params[ 'type' ] );

		if( !$type ){
			return;
		}

		$params['id_queue_type'] = $type->id_queue_type;

		if ($params['seconds']) {
			$params['date_start'] = date('Y-m-d H:i:s', time() + $params['seconds']);
			unset($params['seconds']);
		}

		$params['status'] = self::STATUS_NEW;

		if( $params[ 'id_order' ] && $params[ 'id_order' ] == 171288 ){
			return;
		}

		$q = new Crunchbutton_Queue($params);
		$q->save();

		return $q;
	}

	public function __construct($id = null) {
		parent::__construct();
		$this
			->table('queue')
			->idVar('id_queue')
			->load($id);
	}
}
