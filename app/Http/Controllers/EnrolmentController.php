<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Requests\CreateRegisterRequest;
use Auth;
use DateTime;
use App\User;
use App\Role;
use App\Enrolment;
use Mail;
use App\House;
use App\Track;

class EnrolmentController extends Controller
{
    public function __construct(){
 //       $this->middleware('cors');
        $this->middleware('auth0.jwt');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index() {
        $user = Auth::user();
        return $user->is_admin ? House::with('enrolment.roles')->with('enrolment.users.enrolment.roles')->get() : response()->json(['message' =>'not authorized to view enrolment details', 'code'=>401], 401);

//        return response()->json(['data'=>$users], 200);
    }


    /* Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CreateRegisterRequest $request)
    {           
        $user = Auth::user();
        if ($user->id != $request->user_id) {
            $most_powerful = $user->enrolledClasses()->whereHouseId($request->house_id)->with('roles')->min('role_id');
            if (!$most_powerful || $most_powerful > $role_to_enrol->id && !$user->is_admin) {        // administrator 
                return response()->json(['message'=>'No authorization to enrol', 'code'=>203], 203);
            }
            $enrol_user  = User::findorfail($request->user_id);
        }
        else {
            $enrol_user = $user;
        }
        $date = new DateTime('now');
        $role_to_enrol = Role::where('role','LIKE',$request->role)->first();
        $class_to_enrol = \App\House::findorfail($request->house_id);

        $user_id = $enrol_user ? $enrol_user->id : $user->id; 
        $mastercode = rand ( 10000000, 99999999);
        $register = Enrolment::firstOrNew(['user_id'=>$user->id, 'house_id'=>$request->house_id, 'role_id'=>$role_to_enrol->id]);
        $register->fill(['start_date'=>new DateTime('now'),'expiry_date'=>$date->modify('+1 year'), 'payment_email'=>$user->email, 'purchaser_id'=>$user->id, 'mastercode'=>$mastercode, 'amount_paid'=>$request->amount_paid, 'places_alloted'=>$request->places_alloted, 'transaction_id'=>$request->transaction_id, 'currency_code'=>$request->currency_code, 'payment_status'=>'paid'])->save();
        $note = 'Dear '.$user->firstname.',<br><br>Thank you for signing up with us on the '.$class_to_enrol->description.' program! <br><br>Your mastercode is <b>'.$mastercode.'</b>, which can be used for enrollment of '.$request->places_alloted.' students.<br><br> Once your student logs in for the first time, the enrolment starts.<br><br> Please instruct your student to proceed to quiz.all-gifted.com to enrol for the course and begin a diagnostic test.<br><br> Should you have any queries, please do not hesitate to contact us at info.allgifted@gmail.com<br><br>Thank you. <br><br><i>This is an automated machine generated by the All Gifted System.</i>';
        Mail::queue([],[], function ($message) use ($user,$note) {
            $message->from(env("MAIL_ORDER_ADDRESS"), 'All Gifted Admin')
                    ->to($user->email)->cc('info.allgifted@gmail.com')
                    ->subject('Thank you for your registration')
                    ->setBody($note, 'text/html');
        });
        return response()->json(['message' => 'Registration successful. Within an hour, you should receive an email at '.$user->email.' with details of how to enrol and start the program.', 
            'mastercode' => $mastercode,
            'places_alloted'=>$request->places_alloted, 'code'=>201]);
    }
    
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function user_houses() {
        $user = Auth::user();
        $houses = $user->studentHouse()->with('tracks.skills.skill_maxile')->with('tracks.track_passed')->get();

        foreach ($houses as $house) {
          $house['course_maxile'] = Enrolment::whereUserId($user->id)->whereHouseId($house->id)->whereRoleId(6)->pluck('progress')->first();
          $house['accuracy'] = $user->accuracy();
          $house['tracks_passed'] = count($house->tracks->intersect($user->tracksPassed));
          $house['total_tracks'] = count($house->tracks);
          $house['skill_passed'] = count(\App\Skill::whereIn('id', \App\Skill_Track::whereIn('track_id',$house->tracks()->pluck('id'))->pluck('skill_id'))->get()->intersect($user->skill_user()->whereSkillPassed(TRUE)->get()));
          $house['total_skills'] = count(\App\Skill_track::whereIn('track_id', $house->tracks()->pluck('id'))->get());
          $house['radarChartLabels'] = $user->fields->pluck('field');
          $house['radarChartData'] = [['data'=> $house['radarChartLabels']? \App\FieldUser::whereUserId($user->id)->orderBy('field_id')->pluck('field_maxile'):0, 'label'=>'Field Maxile']];
          $house['target_score'] = $house->course()->pluck('end_maxile_score')->first(); 
        }

        return response()->json(['message' =>'Successful retrieval of enrolment.', 'houses'=>$houses, 'code'=>201], 201);
    }
    
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function teacher_houses() {
        $user = User::find(1);//Auth::user();
        $houses = $user->teachHouse()->with(['tracks.owner','tracks.skills','tracks.field','tracks.status','tracks.level','enrolledStudents.fieldMaxile','enrolledStudents.tracksPassed','enrolledStudents.completedtests'])->get();
        foreach ($houses as $class) {
            $class['average_progress']=$class->studentEnrolment->avg('progress');
            $class['lowest_progress'] = $class->studentEnrolment->min('progress');
            $class['highest_progress'] = $class->studentEnrolment->max('progress');
            $class['students_completed_course'] = $class->studentEnrolment->where('expiry_date','<', new DateTime('today'))->count();         
            $class['chartdata']=[$class->studentEnrolment()->where('progress','<', 40)->count(),$class->studentEnrolment()->where('progress','>=', 40)->where('progress', '<',80)->whereRoleId(6)->count(),$class->studentEnrolment()->where('progress','>=', 80)->count()];
            $class['tracksdata'] = $class->tracks()->pluck('track');
            $class['barchartdata'] = [['data'=> \App\TrackUser::whereIn('track_id', House::find(1)->tracks()->pluck('id'))->whereIn('user_id', House::find(1)->enrolledStudents()->pluck('id'))->avg('track_maxile') ? \App\TrackUser::whereIn('track_id', House::find(1)->tracks()->pluck('id'))->whereIn('user_id', House::find(1)->enrolledStudents()->pluck('id'))->avg('track_maxile'):0 , 'label'=>'Average Maxile']];
        }

        return response()->json(['message' =>'Successful retrieval of teacher enrolment.', 'houses'=>$houses, 'code'=>201], 201);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function show($id) {
        $house=House::findorfail($id);
        $users = User::with('enrolment.roles')->whereHouseId($id)->get();
        return response()->json(['message' =>'Successful retrieval of enrolment.', 'users'=>$users, 'code'=>201], 201);
    }

}