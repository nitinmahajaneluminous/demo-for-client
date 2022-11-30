<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Models\CitiesModel;
use App\Models\CarParksModel;

class CarparkController extends Controller
{
	public function getCities()
	{
    	$cities=CitiesModel::where('status','active')
    					->where('deleted_at',null)
    					->get(['id','name']);
    	
    	return response()->json($cities);
    }

    public function getCarParks(Request $request)
    {
    	$validator=Validator::make($request->all(),[
    		'city_id' => 'required'
    	]);
    	
    	if($validator->fails())
    	{
    		$messages=$validator->messages();
    		
    		if($messages->get('city_id'))
    		{
    			return response()->json(['status'=> config('constants.validation_error'), 'message'=> $messages->get('city_id')[0]]);
    		}
    	}
    	else
    	{
	    	$city=trim($request->city_id);
	    	
	    	$carparks=CarParksModel::where('city',$city)
	    							->where('status','active')
	    							->where('deleted_at',null)
	    							->get(['id','name','short_duration_parking','weekend_parking','postcode','extended_hour']);

	    	return response()->json($carparks);
    	}
    }

    public function getPaymentType()
    {
    	$payment_type=['None','Go Cardless','Square','BACs'];
    	return response()->json($payment_type);
    }

    public function getNewParkToken(){
    	$data = getNewparkAPI('Bentall Centre');
    	return $data['token'];
    }
}
