<?php

namespace App\Http\Controllers\Api;

use App;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\BaseController;

use App\Models\CustomersModel;
use App\Models\CustomerVehicalRegModel;
use App\Models\ProspectiveNewCustomerModel;
use App\Models\CustomerCarparkRequestModel;
use App\Models\DvlaRegModel;
use DVLASearch\SDK\Clients\Vehicle;


use JWTAuth;
use App\Repositories\CustomersRepository\CustomersRepository;
use App\Repositories\CommonRepository\CommonRepository;


/* Refered https://medium.com/@hdcompany123/laravel-5-7-and-json-web-tokens-tymon-jwt-auth-d5af05c0659a for JWT */

class AuthController extends BaseController
{
    function __construct(CustomersModel $CustomersModel, CustomersRepository $CustomersRepository,CommonRepository $CommonRepository)
    {
    	//$this->middleware('auth:api');
        $this->CustomersRepository = $CustomersRepository;
        $this->CustomersModel    = $CustomersModel;
        $this->CommonRepository = $CommonRepository;
    }

    public function logRequestResponse(Request $request)
    {
        try
        {
            DB::table('log_request_response')
            ->insert(
                array(
                    'request'   => $request->req,
                    'response'  => $request->res,
                    'created_by'=> $request->pcp_client_id,
                    'api'       => $request->api
                )
            );

            return response()->json(['status'=> config('constants.success'), 'message'=> 'Logged Successfully']);
        }
        catch(Exception $ex)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $ex->getMessage());
            return response()->json(['status'=> config('constants.db_error'), 'message'=> $ex]);
        }
    }

    public function register(Request $request)
    {
        //$request->pcp_client_id =  mb_convert_encoding($request->pcp_client_id,'UTF-8','UTF-8');
        //$request->password =  mb_convert_encoding($request->password,'UTF-8','UTF-8');
        $validator = Validator::make($request->all(), 
        [
            'pcp_client_id' =>'required',
            'password' => 'required|min:6',
        ]);
        if($validator->fails()) 
        {
            $messages=$validator->messages();
            if($messages->get('pcp_client_id'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('pcp_client_id')[0]]);
            }
            else if($messages->get('password'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('password')[0]]);
            }
        }
        else if (!preg_match('/[A-Za-z].*[0-9]|[0-9].*[A-Za-z]/', trim($request->password)))
        {
            return response()->json(['status'=> config('constants.validation_error'), 'message'=> 'Password must contain atleast one alphabet and one digit and must be atleast 6 charactors']);
        }
        else
        {
            try
            {

                $response=$this->CustomersRepository->registerFromApi($request);
            }
            catch(Exception $ex)
            {
                Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $ex->getMessage());
                $response = array();
                $response['status'] = config('constants.db_error');
                $response['message'] = $ex->getMessage();
            }

            return response()->json($response);
        }
    } 

    public function verify(Request $request)
    {
    	$validator = Validator::make($request->all(), 
        [
            'pcp_client_id' => 'required',
        ]);

        if($validator->fails()) 
        {
        	$messages=$validator->messages();
        	if($messages->get('pcp_client_id'))
        	{
            	return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('pcp_client_id')[0]]);
            }
        }
        /*else if (!preg_match('/[A-Za-z].*[0-9]|[0-9].*[A-Za-z]/', $request->password))
        {
            return response()->json(['status'=> config('constants.validation_error'), 'message'=> 'Password must contain atleast one digit']);
        }*/
        else
        {
        	try
        	{
        		//$response=$this->CustomersRepository->registerFromApi($request);
                $response=$this->CustomersRepository->verifyClientFromApi($request);
        	}
        	catch (\Exception $exception) 
            {
                Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
                $response = array();
                $response['status'] = config('constants.db_error');
                $response['message'] = $exception->getMessage();
            }

            return response()->json($response);
        }
    }

    public function forgetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), 
                    [
                        'email' => 'required|email',
                    ]);

        if ($validator->fails()) 
        {
            return response()->json(['status'=> config('constants.validation_error'), 'message'=> "Email is required and must be a valid email address"]);
        }
        else
        {

            try
            {
                $response = $this->CustomersRepository->forgetPasswordFromApi($request->email);

                return response()->json($response);
            }
            catch (\Exception $exception) 
            {
                Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
                return response()->json(['status'=> config('constants.db_error'), 'message'=> $exception->getMessage()]);
            }
        }
    }

    public function changePassword(Request $request)
    {
        $response = array();
        $validator = Validator::make($request->all(), 
        [
            'old_password' => 'required',
            'password' => 'required|min:6',
            'userid'=>'required'
        ]);

        if ($validator->fails()) 
        {
            $messages=$validator->messages();
            if($messages->get('userid'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('userid')[0]]);
            }
            else if($messages->get('old_password'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('old_password')[0]]);
            }
            else if($messages->get('password'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> "Password is required and must be minimum 6 charactors"]);
            }
        }
        
        else if (!preg_match('/[A-Za-z].*[0-9]|[0-9].*[A-Za-z]/', $request->password))
        {
            return response()->json(['status'=> config('constants.validation_error'), 'message'=> 'Password must contain atleast one digit']);
        }
        try
        {
            if(auth('api')->parseToken())
            {
            	if(auth('api')->check())
            	{
                	$response = $this->CustomersRepository
                             ->changePasswordFromApi($request);
                	return Response::json($response);
                }
                else
                {
                	$response['status'] = config('constants.token_error');
                	$response['message'] = "Invalid Token";
                	return Response::json($response);
                }
            }
            else
            {
                $response['status'] = config('constants.token_error');
                $response['message'] = $exception->getMessage();
                return Response::json($response);
            }
        }
        catch (\Exception $exception) 
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            $response = array();
            $response['status'] = config('constants.db_error');
            $response['message'] = $exception->getMessage();
            return Response::json($response);
        }
        
    }

    public function resetPasswordFromApi(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reset_key'=>'required',
            'password' => 'required',
        ]);

        if ($validator->fails()) 
        {
            return response()->json(['status'=> config('constants.validation_error'), 'message'=> 'Password is required']);
        }
        try
        {
            $email = CustomersModel::where('password_reset_key',$request->reset_key)->value('email');
            CustomersModel::where('email',$email)->update([
               'password'=>bcrypt($request->password) 
            ]);

            return response()->json(['status'=> config('constants.success'), 'message'=> 'Password reset successfull']);
        }
        catch(Exception $ex)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $ex->getMessage());
            return response()->json(['status'=> config('constants.db_error'), 'message'=> $ex->getMessage()]);
        }
    }

    public function login(Request $request)
    {
        $rules = [
            'email' => 'required|email',
            'password' => 'required',
        ];

        $validator = Validator::make($request->all(), $rules);
        if($validator->fails()) 
        {
            $messages=$validator->messages();
            if($messages->get('email'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> 'Email is required and must be valid Email address']);
            }
            else if($messages->get('password'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> "Password is required"]);
            }
            
        }
        else
        {
            try
            {
                $response = $this->CustomersRepository
                             ->loginFromApi($request);
            }
            catch (\Exception $exception) 
            {
                Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
                $response = array();
                $response['status'] = config('constants.db_error');
                $response['message'] = $exception->getMessage();
            }

            return Response::json($response); 
        }
    }

    public function editCustomer(Request $request)
    {
    	$validator = Validator::make($request->all(), 
        [
        	'customer_id'=>'required',
            'first_name' => 'required',
            'last_name' => 'required',
        ]);

        if($validator->fails()) 
        {
        	$messages=$validator->messages();
        	if($messages->get('customer_id'))
        	{
        		return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('customer_id')[0]]);
        	}
        	else if($messages->get('first_name'))
        	{
            	return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('first_name')[0]]);
            }
            else if($messages->get('last_name'))
            {
				return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('last_name')[0]]);
            }
            else if($messages->get('email'))
            {
            	return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('email')[0]]);
            }
        }
        else
        {
            if(auth('api')->parseToken())
            {
                if(auth('api')->check())
                {
                	if($this->CustomersRepository
                           ->updateCustomerFromApi($request))
                	{
                		
                		$response=$this->CustomersRepository->updateSubscriptionStatusFromApi($request);

                		return response()->json($response);
                	}
                	else
                	{
                		return response()->json(['status'=>false,'message'=>'Customer not updated']);
                	}
                }
                else
                {
                    $response['status'] = config('constants.token_error');
                    $response['message'] = "Invalid Token";
                    return Response::json($response);
                }
            }
            else
            {
                $response['status'] = config('constants.token_error');
                $response['message'] = $exception->getMessage();
                return Response::json($response);
            }
        }
    }

    public function logout()
    {
        auth('api')->logout();

        return response()->json(['message' => 'Successfully logged out']);
    }

    public function editRegistrationNumbers(Request $request)
    {
        //dd($request->regNumbers);
    	$validator = Validator::make($request->all(), 
        [
        	'customer_id'=>'required',
            
        ]);

        if($validator->fails()) 
        {
			$messages=$validator->messages();
        	if($messages->get('customer_id'))
        	{
        		return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('customer_id')[0]]);
        	}
        }
        else
        {
            if(!isset($request->regNumbers) || count($request->regNumbers)==0)
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> 'registration numbers must contain atleast one data']);
            }
            /*if(auth('api')->parseToken())
            {
                if(auth('api')->check())
                {*/
                	$response=$this->CustomersRepository
                           ->updateRegistrationFromApi($request);
                    /* make pipedrive changes */
                    $client_no = CustomersModel::where('id',$request->customer_id)->value('client_no');

                    $collect['id'] = $this->CommonRepository->userExistsInPipedriveByPCPId($client_no);
                
                    $reg_numbers  = CustomerVehicalRegModel::where('fk_customer_id',$request->customer_id)
                                                        ->where('status','active')
                                                        ->pluck('vehicle_registration_number')
                                                        ->all();

                    //$collect['bcf37ccb665911d769b260c36ef77c1b6109fe43'] = $reg_numbers;//test
                    $collect['57b9d67acd1fb667856506008264dc6eebfb1be6'] = $reg_numbers;//live

                    $this->CommonRepository->updateRegIntoPipedrive($collect);
                	return response()->json($response);
                /*}
                else
                {
                    $response['status'] = config('constants.token_error');
                    $response['message'] = "Invalid Token";
                    return Response::json($response);
                }
            }
            else
            {
                $response['status'] = config('constants.token_error');
                $response['message'] = $exception->getMessage();
                return Response::json($response);
            }*/
        }
    }

    public function confirmLogin(Request $request)
    {
        try
        {
            $response=$this->CustomersRepository
                            ->confirmLogin($request);
            return response()->json($response);
        }
        catch(Exception $ex)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $ex->getMessage());
            $response['status'] = config('constants.token_error');
            $response['message'] = $ex->getMessage();
            return Response::json($response);
        }
    }

    public function viewCustomer(Request $request)
    {
        $validator = Validator::make($request->all(), 
        [
            'customer_id'=>'required'
        ]);

        if($validator->fails()) 
        {
            $messages=$validator->messages();
            if($messages->get('customer_id'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('customer_id')[0]]);
            }
        }
        else
        {
            try
            {
                if(auth('api')->parseToken())
                {
                    if(auth('api')->check())
                    {
                        $response=$this->CustomersRepository
                                        ->viewCustomerFromApi($request);
                        return response()->json($response);
                    }
                    else
                    {
                        $response['status'] = config('constants.token_error');
                        $response['message'] = "Invalid Token";
                        return Response::json($response);
                    }
                }
                else
                {
                    $response['status'] = config('constants.token_error');
                    $response['message'] = $exception->getMessage();
                    return Response::json($response);
                }
            }
            catch(Exception $ex)
            {
                $response['status'] = config('constants.token_error');
                $response['message'] = $exception->getMessage();
                return Response::json($response);
            }
        }
    }

    public function requestCarpark(Request $request)
    {
        $validator = Validator::make($request->all(), 
        [
            'customer_id'=>'required',
            'carpark_id'=>'required',
            'city_id'=>'required',
            'status'=>'required',
            'token' =>'required'
        ]);

        if($validator->fails()) 
        {
            $messages=$validator->messages();
            if($messages->get('customer_id'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('customer_id')[0]]);
            }else if($messages->get('carpark_id'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('carpark_id')[0]]);
            }
            else if($messages->get('city_id'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('city_id')[0]]);
            }
            else if($messages->get('token'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('token')[0]]);
            }
            else if($messages->get('status'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('status')[0]]);
            }
        }
        else
        {
            if(auth('api')->parseToken())
            {
                if(auth('api')->check())
                {
                    $customer_id = trim($request->customer_id);
                    $carpark_id = trim($request->carpark_id);
                    $city_id    = trim($request->city_id);

                    CustomerCarparkRequestModel::updateOrCreate(
                        ['fk_customer_id'=>$customer_id,'fk_carpark_id'=>$carpark_id,'fk_city_id'=>$city_id],
                        ['status'=>$request->status,'updated_at'=>now()]
                    );

                    if($request->status == 'requested')
                    {
                        CustomersModel::where('id',$customer_id)
                                    ->update([
                                        'is_carpark_requested'=>'yes'
                                    ]);
                    }
                    else if($request->status == 'cancelled')
                    {
                        CustomersModel::where('id',$customer_id)
                                    ->update([
                                        'is_carpark_requested'=>'no'
                                    ]);
                    }               
                    $response['status'] = config('constants.success');
                    $response['message'] = 'Carpark requested successfully';
                    return Response::json($response);
                }
                else
                {
                    $response['status'] = config('constants.token_error');
                    $response['message'] = "Invalid Token";
                    return Response::json($response);
                }
            }
            else
            {
                $response['status'] = config('constants.token_error');
                $response['message'] = $exception->getMessage();
                return Response::json($response);
            }
        }
    }

    public function getRequestedCarparks(Request $request)
    {
        $validator = Validator::make($request->all(), 
        [
            'customer_id'=>'required',
            'token' =>'required'
        ]);

        if($validator->fails()) 
        {
            $messages=$validator->messages();
            if($messages->get('customer_id'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('customer_id')[0]]);
            }
            else if($messages->get('token'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('token')[0]]);
            }
        }
        else
        {
            if(auth('api')->parseToken())
            {
                if(auth('api')->check())
                {
                    $customer_id = trim($request->customer_id);

                    $response['carparks']=$this->CustomersRepository
                                               ->getRequestedCarparks($customer_id,true);
                    

                    $response['status'] = config('constants.success');
                    $response['message'] = 'Carpark requested successfully';
                    return Response::json($response);
                }
                else
                {
                    $response['status'] = config('constants.token_error');
                    $response['message'] = "Invalid Token";
                    return Response::json($response);
                }
            }
            else
            {
                $response['status'] = config('constants.token_error');
                $response['message'] = $exception->getMessage();
                return Response::json($response);
            }
        }
    }

   /* public function cancelCarparkRequest(Request $request)
    {
        $validator = Validator::make($request->all(), 
        [
            'request_id'=>'required',
            'token' =>'required'
        ]);

        if($validator->fails()) 
        {
            $messages=$validator->messages();
            if($messages->get('request_id'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('request_id')[0]]);
            }
        }
        else
        {
            if(auth('api')->parseToken())
            {
                if(auth('api')->check())
                {
                    CustomerCarparkRequestModel::where('id',$request->request_id)
                                                ->update([
                                                    'status'=>'cancelled',
                                                    'updated_at'=>now()
                                                ]);
                    

                    $response['status'] = config('constants.success');
                    $response['message'] = 'Carpark cancelled successfully';
                    return Response::json($response);
                }
                else
                {
                    $response['status'] = config('constants.token_error');
                    $response['message'] = "Invalid Token";
                    return Response::json($response);
                }
            }
            else
            {
                $response['status'] = config('constants.token_error');
                $response['message'] = $exception->getMessage();
                return Response::json($response);
            }
        }
    }*/

    public function saveDvlaDetails(Request $request)
    {
        $validator = Validator::make($request->all(), 
        [
            'reg_no'            => 'required', 
            'tax_status'         => 'required', 
            'tax_due_date'        => 'required',
            'mot_status'         => 'required', 
            //'mot_expiry_date'     => 'required', 
            'make'              => 'required', 
            'manufacture_year' => 'required', 
            'engine_capacity'    => 'required', 
            'co2_emissions'      => 'required', 
            'fuel_type'          => 'required', 
            'type_approval'      => 'required', 
            'status'            => 'required',
            //'token' =>'required'
        ]);

        if($validator->fails()) 
        {
            //dd("failed");
            $messages=$validator->messages();
            //dd($messages);
            if($messages->get('reg_no'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('reg_no')[0]]);
            }
            /*else if($messages->get('token'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('token')[0]]);
            }*/
            else if($messages->get('tax_status'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('tax_status')[0]]);
            }
            else if($messages->get('tax_due_date'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('tax_due_date')[0]]);
            }
            else if($messages->get('mot_status'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('mot_status')[0]]);
            }
            /*else if($messages->get('mot_expiry_date'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('mot_expiry_date')[0]]);
            }*/
            else if($messages->get('make'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('make')[0]]);
            }
            else if($messages->get('manufacture_year'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('manufacture_year')[0]]);
            }
            else if($messages->get('engine_capacity'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('engine_capacity')[0]]);
            }
            else if($messages->get('co2_emissions'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('co2_emissions')[0]]);
            }
            else if($messages->get('fuel_type'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('fuel_type')[0]]);
            }
            else if($messages->get('type_approval'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('type_approval')[0]]);
            }
            else if($messages->get('status'))
            {
                return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('status')[0]]);
            }
        }
        else
        {
           /* if(auth('api')->parseToken())
            {
                if(auth('api')->check())
                {*/
                    try
                    {
                        $DvlaRegModel = new DvlaRegModel;
                        $DvlaRegModel->reg_no = trim(str_replace(' ', '', strtoupper($request->reg_no)));
                        $DvlaRegModel->tax_status =  $request->tax_status;       
                        $DvlaRegModel->tax_due_date = $request->tax_due_date;
                        $DvlaRegModel->mot_status   = $request->mot_status;   
                        $DvlaRegModel->mot_expiry_date =$request->mot_expiry_date?$request->mot_expiry_date:NULL;   
                        $DvlaRegModel->make = $request->make;             
                        $DvlaRegModel->manufacture_year = $request->manufacture_year;
                        $DvlaRegModel->engine_capacity = $request->engine_capacity;
                        $DvlaRegModel->co2_emissions   = $request->co2_emissions;
                        $DvlaRegModel->fuel_type      = $request->fuel_type;
                        $DvlaRegModel->type_approval  = $request->type_approval;   
                        $DvlaRegModel->status          = $request->status; 
                        $DvlaRegModel->is_from_portal = 1;
                        //$DvlaRegModel->created_by = auth()
                        $DvlaRegModel->save();
                        $response['status'] = config('constants.success');
                        $response['message'] = 'Dvla details stored successfully';
                        return Response::json($response);
                    }
                    catch(Exception $ex)
                    {
                        Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $ex->getMessage());
                        $response['status'] = config('constants.db_error');
                        $response['message'] = 'Something went wrong.';
                    }
                /*}
                else
                {
                    $response['status'] = config('constants.token_error');
                    $response['message'] = "Invalid Token";
                    return Response::json($response);
                }
            }
            else
            {
                $response['status'] = config('constants.token_error');
                $response['message'] = $exception->getMessage();
                return Response::json($response);
            }*/
        }
    }

    public function test(Request $request)
    {
        /*try
        {*/
        /*$curl = curl_init();

          curl_setopt_array($curl, array(
          CURLOPT_URL => "https://driver-vehicle-licensing.api.gov.uk/vehicle-enquiry/v1/vehicles",
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => "",
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => "POST",
          CURLOPT_POSTFIELDS =>"{\n\t\"registrationNumber\": \"HV65OSJ\"\n}",
          CURLOPT_HTTPHEADER => array(
            "x-api-key: ehT1S1wf2882XN3q0htPq78gBrW4UfY31lINdZyT",
            "Content-Type: application/json"
          ),
        ));

        $response = curl_exec($curl);
        dd($response);*/
        //curl_close($curl);
        
       /* }
        catch(Exception $ex)
        {
            dd($ex);
        }*/
        $client = new Vehicle('ehT1S1wf2882XN3q0htPq78gBrW4UfY31lINdZyT');
        $vehicle = $client->get('MT09 VCA');

        // $vehicle->error will be set if the number plate isn't attached to a vehicle
        if(!isset($vehicle->error)) {
          var_dump($vehicle);
        } else {
          var_dump('No vehicle found for ' . $vehicle->plate);
        }
    }
}
