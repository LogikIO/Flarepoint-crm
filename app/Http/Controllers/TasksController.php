<?php namespace App\Http\Controllers;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\Tasks;
use App\Comment;
use Session;
use App\User;
use App\Client;
use Illuminate\Http\Request;
use Auth;
use App\Settings;
use Gate;
use App\TaskTime;
use Datatables;
use Carbon;
use App\Dinero;
use Notifynder;
use DB;
use App\Billy;
use App\Economic;
use App\Integration;
use App\Activity;


class TasksController extends Controller {

	protected $request;
	public function __construct(Request $request)
	{
		$this->request = $request;
	}
	/**
	 * Display a listing of the resource.
	 *
	 * @return Response
	 */
	public function index()
	{

		//DB::statement('CREATE DATABASE datavase3');
        $tasks = Tasks::orderBy('deadline');
		return view('tasks.index')->withTasks($tasks);

			
	}

	public function anyData()
	{
		
		$tasks = Tasks::select(['id', 'title', 'created_at', 'deadline', 'fk_user_id_assign'])->where('status', 1)->get();
		return Datatables::of($tasks)
		->addColumn('titlelink', function ($tasks) {
                return '<a href="tasks/'.$tasks->id.'" ">'.$tasks->title.'</a>';
            })
		->editColumn('created_at', function ($tasks) {
	            return $tasks->created_at ? with(new Carbon($tasks->created_at))
	            ->format('d/m/Y') : '';
	        })
		->editColumn('deadline', function ($tasks) {
	            return $tasks->created_at ? with(new Carbon($tasks->created_at))
	            ->format('d/m/Y') : '';
	        })
		->editColumn('fk_user_id_assign', function ($tasks) {
	            return $tasks->assignee->name;
	            
	        })->make(true);
	}


	/**
	 * Show the form for creating a new resource.
	 *
	 * @return Response
	 */
	public function create()
	{
		$canCreateTask = Auth::user()->canDo('task.create');
		if (!$canCreateTask) {
        Session::flash('flash_message', 'Not allowed to create task!');
        return redirect()->route('users.index');
		}
        
        $users = User::select(array('users.name', 'users.id', DB::raw('CONCAT(users.name, " (", departments.name, ")") AS full_name')))
        ->join('department_user', 'users.id', '=', 'department_user.user_id')
        ->join('departments', 'department_user.department_id', '=', 'departments.id')
        ->lists('full_name', 'id');
        $clients = Client::lists('name', 'id');
        $loggedin = User::find(1);

		return view('tasks.create')->withUsers($users)->withClients($clients);

	}

	/**
	 * Store a newly created resource in storage.
	 *
	 * @return Response
	 */
	public function store(Request $request) // uses __contrust request
	{

    	$this->validate($request, [
            'title' => 'required',
            'description' => 'required',
            'status' => 'required',
            'fk_user_id_assign' => 'required',
            'fk_user_id_created' => '',
            'fk_client_id' => '',
            'deadline' => '']);
        $fk_client_id = $request->get('fk_client_id');	
    	$input = $request = array_merge($this->request->all(),
    	 ['fk_user_id_created' => \Auth::id(), ]);
         //dd($input);
        $task = Tasks::create($input);
        $insertedId = $task->id;

        Session::flash('flash_message', 'Task successfully added!'); //Snippet in Master.blade.php
		Notifynder::category('task.assign')
        ->from(\Auth::id())
        ->to($task->fk_user_id_assign)
        ->url(url('tasks', $insertedId))
        ->expire(Carbon::now()->addDays(14))
        ->send();

         $activityinput = array_merge(
		['text' => 'Task ' . $task->title . ' was created by '. $task->taskCreator->name . ' and assigned to' . $task->assignee->name,
		 'user_id' => Auth::id(),
		 'type' => 'task', 
		 'type_id' =>  $insertedId]);
		
        Activity::create($activityinput);
        return redirect()->to("/tasks/{$task->id}");
	}

   

	/**
	 * Display the specified resource.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function show(Request $request, $id )
	{
		
		//dd($request->all());
     	 $settings = Settings::findOrFail(1);
        $companyname = $settings->company;
        $activity = Activity::where('type_id', $id)
        ->where('type', 'task')->get();

        
		
		    $api = Integration::getApi(1, 'billing');
		    
			$invoiceContacts = $api->getContacts();
			
			$apiConnected = true;

	
        $users = User::select(array('users.name', 'users.id', DB::raw('CONCAT(users.name, " (", departments.name, ")") AS full_name')))
        ->join('department_user', 'users.id', '=', 'department_user.user_id')
        ->join('departments', 'department_user.department_id', '=', 'departments.id')
        ->lists('full_name', 'id');

		$tasks = Tasks::findOrFail($id);
		$timemanger = TaskTime::where('fk_task_id', $id)->get();

        
		return view('tasks.show')->withTasks($tasks)->withUsers($users)->withContacts($invoiceContacts)->withTasktimes($timemanger)->withCompanyname($companyname)->withApiconnected($apiConnected);


	}


/**
 * Sees if the Settings from backend allows all to complete taks 
 * or only assigned user. if only assigned user:
 * @param  [Auth]  $id Checks Logged in users id
 * @param  [Model] $task->fk_user_id_assign Checks the id of the user assigned to the task 
 * If Auth and fk_user_id allow complete else redirect back if all allowed excute
	 * else stmt*/
	public function updatestatus($id, Request $request)
	{

		$task = Tasks::findOrFail($id);
		$isAdmin = Auth::user()->hasRole('admin');
		$settings = Settings::all();
		$settingscomplete = $settings[0]['task_complete_allowed'];


		if ($settingscomplete == 1  && Auth::user()->id != $task->fk_user_id_assign || $isAdmin) 
		{
        	 Session::flash('flash_message_warning', 'Only assigned user are allowed to close Task.');
	        	return redirect()->back();

		}
		$input = $request->get('status');
		$input = array_replace($request->all(), ['status' => 2]);
		$task->fill($input)->save();

		$activityinput = array_merge(
		['text' => 'Task was completed by '. Auth::user()->name,
		 'user_id' => Auth::id(),
		 'type' => 'task', 
		 'type_id' =>  $id]);
        Activity::create($activityinput);
		return redirect()->back();

	}


	public function updateassign($id, Request $request)
	{

		$task = Tasks::with('assignee')->findOrFail($id);

		$settings = Settings::all();
		$isAdmin = Auth::user()->hasRole('Admin');
		$settingscomplete = $settings[0]['task_assign_allowed'];
		$insertedName = $task->assignee->name;

		if ($settingscomplete == 1  && Auth::user()->id != $task->fk_user_id_assign || $isAdmin) 
		{
        	 Session::flash('flash_message_warning', 'Only assigned user are allowed to assign new user.');
	        	return redirect()->back();

		}
				$input = $request->get('fk_user_id_assign');

				$input = array_replace($request->all());
				$task->fill($input)->save();
				$task = $task->fresh(); 
				$insertedName = $task->assignee->name;
				

				$activityinput = array_merge(
				['text' => auth::user()->name.' assigned task to '. $insertedName,
				 'user_id' => Auth::id(),
				 'type' => 'task', 
				 'type_id' =>  $id]);
		        Activity::create($activityinput);

				return redirect()->back();

	}
	public function updatetime($id, Request $request)
	{
		  	$this->validate($request, [
            'title' => 'required',
            'comment' => '',
            'time' => 'required',
            'value' => 'required',
   				]);

		$task = Tasks::findOrFail($id);
		
		
		$settings = Settings::all();
		$isAdmin = Auth::user()->hasRole('admin');
		$settingscomplete = $settings[0]['task_assign_allowed'];
		
		if ($settingscomplete == 1  && Auth::user()->id != $task->fk_user_id_assign || $isAdmin) 
			{
    		 Session::flash('flash_message_warning', 'Only assigned user are allowed to update time.');
		        	return redirect()->back();

			}


				$input = array_replace($request->all(), ['fk_task_id'=>"$task->id"]);
				
				TaskTime::create($input);
				$activityinput = array_merge(
				['text' => Auth::user()->name.' Inserted a new time for this task',
				 'user_id' => Auth::id(),
				 'type' => 'task', 
				 'type_id' =>  $id]);
		        Activity::create($activityinput);
				Session::flash('flash_message', 'Time has been updated');
				return redirect()->back();


	}

	public function invoice($id, Request $request){
		
		$contatGuid = $request->invoiceContact;
		
		$taskname = Tasks::find($id);
		$timemanger = TaskTime::where('fk_task_id', $id)->get();
			$sendMail = $request->sendMail;
		$productlines = [];
		foreach ($timemanger as $time) {
	      	$productlines[] = array(
		      'Description' => $time->title,
		      'Comments' => $time->comment,
		      'BaseAmountValue' => $time->value,
		      'Quantity' => $time->time,
		      'AccountNumber' => 1000,
		      'Unit' => 'hours');
		}

		$api = Integration::getApi(1, 'billing');

		
		$results = $api->createInvoice( [
        	'Currency' => 'DKK',
        	'Description' => $taskname->title,
        	'contactId' => $contatGuid,
    	    'ProductLines' => $productlines]);
		
    	  
	   

	    
		
       		
	      //$booked = $api->bookInvoice($guid, $timestamp);
	      if ($sendMail == true) {
	      $bookGuid = $booked->Guid;
	      $bookTime = $booked->TimeStamp;
	      $api->sendInvoice($bookGuid, $bookTime);
	      	}
	      
   		return redirect()->back();

		
	}

	public function invoiceDinero($id, Request $request)
	{

		$clientId = config('services.dinero.client');
	    $clientSecret = config('services.dinero.secret');
	    $organizationId = config('services.dinero.org');
	    $apiKey = config('services.dinero.api');
	    $sendMail = $request->sendMail;
	    Dinero::initialize($organizationId, $clientId, $clientSecret, $apiKey);
		$contatGuid = $request->dineroContact;
		$taskname = Tasks::find($id);
	$timemanger = TaskTime::where('fk_task_id', $id)->get();

	foreach ($timemanger as $time) {
      	$productlines[] = array(
	      'Description' => $time->title,
	      'Comments' => $time->comment,
	      'BaseAmountValue' => $time->value,
	      'Quantity' => $time->time,
	      'AccountNumber' => 1000,
	      'Unit' => 'hours');
	}

	$results = Dinero::createInvoice([
        	'Currency' => 'DKK',
        	'Description' => $taskname->title,
        	'ContactGuid' => $contatGuid,
    	    'ProductLines' => $productlines]);
	     

	      $guid = $results->Guid;
	      $timestamp = $results->TimeStamp;
			
      
	      $booked = Dinero::bookInvoice($guid, $timestamp);
	      if ($sendMail == true) {
	      $bookGuid = $booked->Guid;
	      $bookTime = $booked->TimeStamp;
	      Dinero::sendInvoice($bookGuid, $bookTime);
	      	}
	      
   		return redirect()->back();
	}
	/**
	 * Remove the specified resource from storage.
	 *
	 * @param  int  $id
	 * @return Response
	 */
	public function destroy($id)
	{
		//
	}
	public function marked()
	{
		Notifynder::readAll(\Auth::id());
		return redirect()->back();
	}

}
