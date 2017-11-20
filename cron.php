<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Input;
use App\Http\Requests;
use Session;
use Image;
use Carbon\Carbon;
use App\Order;
use App\Label;
use Mail;
use Excel;
use DB;
use Log;

class CronController extends Controller
{
     public function __construct()
    {
        /*$this->middleware('auth');*/
        DB::enableQueryLog();
    }
    
     public function getCreateCsv(){
		 $date = date('Y-m-d H:i:s');   /**get current day**/
		 $dayofweek = date('l', strtotime($date)); 
		  /****for SAUVIGNON BLANC**/
		  $sauvignon  = $this->labelQuery()->where('labels.winetype','=','SAUVIGNON BLANC')->get()->toArray();
		  /****for MERLOT**/
		  $merlot     = $this->labelQuery()->where('labels.winetype','=','MERLOT')->get()->toArray();
		  	/****for CHARDONNAY**/
		  $chardonnay = $this->labelQuery()->where('labels.winetype','=','CHARDONNAY')->get()->toArray();
		  /****for CABERNET SAUVIGNON**/
		  $cabernet   = $this->labelQuery()->where('labels.winetype','=','CABERNET SAUVIGNON')->get()->toArray();
		  
		 /**create log for query run**/
		 Log::useDailyFiles(storage_path().'/logs/cron.log');
		 Log::info(DB::getQueryLog());
	 
		  $path=[];
		  $csvdata = array();
		 if(count($sauvignon) > 0)
		 {
			  Excel::create("sauvignon-".$dayofweek, function($excel) use ($sauvignon) {
				$excel->sheet('Labels', function($sheet) use ($sauvignon)
				{
					 $csvdata = $sauvignon;
					$sheet->loadView('csv.index')->with('csvdata', $csvdata);
					
					 
					 $sheet->row('A1', function($row) { $row->setBackground('#CCCCCC'); });
					
				});
			})->store('csv', storage_path('csv'));
			$path[] = storage_path('csv')."/sauvignon-".$dayofweek.'.csv';
			 
			foreach($sauvignon as $label){ 
				$this->updateOrders($label['id']);
			}
		}
		if(count($merlot) > 0)
		 {
			  Excel::create('merlot-'.$dayofweek, function($excel) use ($merlot) {
				$excel->sheet('Labels', function($sheet) use ($merlot)
				{
					 
					$sheet->fromArray($merlot);
				});
			})->store('csv', storage_path('csv'));
			 $path[] = storage_path('csv')."/merlot-".$dayofweek.'.csv';
			 
			foreach($merlot as $label){ 
				$this->updateOrders($label['id']);
			}
		}
		if(count($chardonnay) > 0)
		 {
			  Excel::create('chardonnay-'.$dayofweek, function($excel) use ($chardonnay) {
				$excel->sheet('Labels', function($sheet) use ($chardonnay)
				{
					 
					$sheet->fromArray($chardonnay);
				});
			})->store('csv', storage_path('csv'));
			$path[] = storage_path('csv')."/sauvignon-".$dayofweek.'.csv';
			 
			foreach($chardonnay as $label){ 
				$this->updateOrders($label['id']);
			}
		}
		 if(count($cabernet) > 0)
		 {
			  Excel::create('cabernet-'.$dayofweek, function($excel) use ($cabernet) {
				$excel->sheet('Labels', function($sheet) use ($cabernet)
				{
					 
					$sheet->fromArray($cabernet);
				});
			})->store('csv', storage_path('csv'));
			$path[] = storage_path('csv')."/cabernet-".$dayofweek.'.csv';
			
			foreach($cabernet as $label){ 
				$this->updateOrders($label['id']);
			}
		}
	   
	   $this->sendMail($path);
	      return response()
            ->json([ 'msg' => '1']);
	 }
	  /***common query for all wine label records***/
	 function labelQuery(){
		  $labels = Label::select('labels.id','labels.winetype','labels.last_name as LabelName','labels.occasion','orders.firstname as FirstName',
			     'orders.lastname as LastName', 'orders.address as Address', 'orders.city as City', 'orders.state as State', 'orders.zip as Zip'
			     , 'orders.phone as Phone', 'orders.email as Email')
			      ->join('orders','orders.label_id','=','labels.id');	
	      return $labels->where('labels.added_to_csv','=',0);		     
		 
	 } 
	 /**update orders***/
	 function updateOrders($id){
		return DB::table('labels')
            ->where('id', $id)
            ->update(['added_to_csv' => 1]);
		 
	 }
	 /***Send mail***/
	 function sendMail($path){
		// $message = [];
		$data_mail = Mail::send('emails.csv', array(), function($message) use ($path) {
			 $message->from('xxxx@xxxx.com', 'xxxxxxx' );
			 $message->to(env('CSV_MAIL_RECEIVER'))->subject('Wine CSV file');
			  $count = count($path);
			  for($i=0;$i<$count;$i++){
				$message->attach($path[$i]); // change i to $i
			  }
		  },true);
		 
	 }
	/***second crone working for order confirmation mail to user***/
	public function getSendConfirmMail(){
		
		 $email =  DB::table('orders')->select('orders.email','orders.id','labels.last_name','labels.occasion','labels.id as label_id')
					->join('labels','labels.id','=','orders.label_id')
					->where(['orders.email_sent' => 0,'labels.order_confirm_mail_sent' => 0 ,'labels.added_to_csv' => 1])->get();
		  
		  foreach($email as $record){ 
			$occasional_photo = url('public/label-images/'.lcfirst($record->last_name).'_'.$record->id.'.jpg') ; 
			$this->orderConfirmationMail($record->email, $record->id, $occasional_photo );
			$this->updateOrdersOnConfirmation($record->id); 
			$this->updateLabelsOnConfirmation($record->label_id);
		    
		   }
		 Log::useDailyFiles(storage_path().'/logs/cron-order-confirm.log');
		 Log::info(DB::getQueryLog());  
		return json_encode(array('msg'=>1));	  
	}
	
	 /***Send mail***/
	 function orderConfirmationMail($email, $order_id, $occasional_photo){
		 $data['order_id']      = $order_id;
		 $data['occasion_photo']= $occasional_photo;
		Mail::send('emails.order_confirmation', $data, function ($message) use ($email) {
			$message->from('xxxx@xxxxx.com', 'xxxxxxx');
			 
			$message->to($email)->subject('xxxxxxxx: YOUR ORDER IS ON THE WAY');
			},true);
		 
		 
	 }
	 
	 /**update order if email sent to customers**/
	 function updateOrdersOnConfirmation($order_id){
		 return DB::table('orders')
            ->where('id', $order_id)
            ->update(['email_sent' => 1,'email_sent_at' => date('Y-m-d H:i:s')]);
		 
	 }
	 
	 /**update order if email sent to customers**/
	 function updateLabelsOnConfirmation($label_id){
		 return DB::table('labels')
            ->where('id', $label_id)
            ->update(['order_confirm_mail_sent' => 1]);
		 
	 }
	 	
}
