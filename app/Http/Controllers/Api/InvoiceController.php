<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\CustomerInvoiceModel;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Session;

use App\Repositories\InvoiceRepository\InvoiceRepositoryInterface;
use App\Repositories\CommonRepository\CommonRepository;

use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Facades\Response;

class InvoiceController extends Controller
{
    public function __construct(InvoiceRepositoryInterface $InvoiceRepositoryInterface)
    {
        $this->BaseRepository = $InvoiceRepositoryInterface;
        $this->CommonRepository = new CommonRepository;
    }

    public function getCustomerInvoices(Request $request)
    {

    	$validator = Validator::make($request->all(), 
        [
            'customer_id' => 'required'
        ]);

        if($validator->fails()) 
        {
        	$messages=$validator->messages();
        	if($messages->get('customer_id'))
        	{
            	return response()->json(['success'=> false, 'message'=> $messages->get('customer_id')[0]]);
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
	                	$response = $this->BaseRepository
	                             ->getCustomerInvoicesFromApi($request);
	                	return Response::json($response);
	                }
	                else
	                {
	                	$response['status'] = false;
	                	$response['message'] = "Invalid Token";
	                	return Response::json($response);
	                }
	            }
	            else
	            {
	                $response['status'] = false;
	                $response['message'] = $exception->getMessage();
	                return Response::json($response);
	            }
        	}
        	catch (\Exception $exception) 
	        {
	            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
	            $response = array();
	            $response['status'] = false;
	            $response['message'] = $exception->getMessage();
	            return Response::json($response);
	        }
        }
    }
}
