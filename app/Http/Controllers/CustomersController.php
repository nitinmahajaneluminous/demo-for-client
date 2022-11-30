<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Validator;  
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use App\Jobs\ProcessInvoiceMails;
use App\Models\CorporatesModel;
use App\Models\XeroModel;
use App\Models\SettingsModel; 
use App\Models\CustomersModel;
use App\Models\CustomerCarparkModel;
use App\Models\CustomerVehicalRegModel;
use App\Models\CarparkclientIdModel; 
use App\Models\UsersModel;
use App\Models\CompanyModel;
use App\Models\ClientAccountLinkModel;
use App\Models\CustomerCarparkRequestModel;
use App\Models\CarparkSubscriptionModel;
use App\Models\ProspectiveNewCustomerModel;
use App\Models\CustomerHistory;
use App\Models\CorporateHistoryModel;
use App\Models\CustomerCredit;
use App\Models\CustomerInvoiceModel;
use App\Models\InvoiceDetailsModel;
use App\Models\CitiesModel;
use App\Models\SeasonTicketRevenueModel;
use App\Models\RevenueDetailsModel;
use App\Models\CarParksModel;
use App\Models\CustomerCityModel;
use App\Models\MinParkModel;
use App\Models\CustomerSessionModel;
use App\Models\CustomerMinParkModel;
use App\Models\CustomerInvoiceMinParkModel;
use App\Repositories\CustomersRepository\CustomersRepositoryInterface;
use App\Repositories\CorporateRepository\CorporateRepository;
use App\Repositories\CompanyRepository\CompanyRepository;
use App\Repositories\CommonRepository\CommonRepository;

use PDF;
use DB;
use DateTime;
use DateInterval;
use DatePeriod;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use niklasravnsborg\LaravelPdf\Facades\Pdf as NPDF;
//use Session;

class CustomersController extends Controller
{
    protected $BaseRepository;
    protected $ViewFolder;
    protected $ViewData;
    protected $ModelTitle;
    protected $ModelPath;

    public function __construct(
    	CustomersRepositoryInterface $CustomersRepositoryInterface,
    	CommonRepository $CommonRepository,CorporateRepository $CorporateRepository,CompanyRepository $CompanyRepository

    )
    {
        $this->BaseRepository  	= $CustomersRepositoryInterface;
        $this->CommonRepository = $CommonRepository;
        $this->CorporateRepository  = $CorporateRepository;
        $this->CompanyRepository    = $CompanyRepository;
        $this->InvoicePath = 'admin.invoices.st';
        $this->InvoicePdfPath = storage_path().'/app/public/Invoice/';

        $this->ModelTitle   = '';
        $this->ViewData     = [];
        $this->ViewFolder   = 'admin.customer.';
        $this->ModelPath   	= url('/customer/');
        $this->ModelTitle  	= 'Customer';
        if(config('app.env')!='prod')
        {
            $this->pipe_drive_client    = new \Pipedrive\Client(null, null, null, config('constants.pipedrive_id_test'));   //test pipedrive key
        }
        else
        {
            $this->pipe_drive_client    = new \Pipedrive\Client(null, null, null, config('constants.pipedrive_id_live'));//live api key 
        }
    }

    public function index()
    {
        $refer_first = session::get('refer_first');
        $refer_st    = session::get('refer_st');
        
        if(!empty($refer_first))
        {
            Session::flash('refer', $refer_first); 
            session::put('refer_first','');

        }
        if(!empty($refer_st))
        {
            Session::flash('refer_st', $refer_st); 
            session::put('refer_st','');
            
        }
        $corporates = $this->CorporateRepository->getcorporateRecords();
        $companys   = $this->CompanyRepository->getCompanyRecords();
    	$this->ViewData['pageTitle'] 	  = 'Manage '.$this->ModelTitle;
        $this->ViewData['text']['add'] 	  = 'New '.$this->ModelTitle;
        $this->ViewData['link']['form']   = $this->ModelPath.'/create';
        $this->ViewData['link']['csv']    = $this->ModelPath.'/importCSV';
        $this->ViewData['corporates']     = $corporates;
        $this->ViewData['companys']       = $companys;
        //update corporate customercount
        //$this->CommonRepository->UpdateCorporateCustomerCount();
        $this->ViewData['link']['form']   = $this->ModelPath.'/create';
        $this->ViewData['text']['import'] = 'Import Customers ';
        $this->ViewData['link']['import'] = str_singular($this->ModelPath).'/uploadfile';
        $this->ViewData['link']['load']   = url('load/customers');
        $this->ViewData['link']['status'] = $this->ModelPath.'/status/update';
        $this->ViewData['link']['delete'] = $this->ModelPath.'/destroy';

        return view($this->ViewFolder.'index', $this->ViewData);
    }
    public function getRecords(Request $request)
    {
        Session::put('corporate_redirect', '');
        $data = $request->all();
        $columns       = [];
        $page          = $data['draw'];
        $limit         = $data['length'];
        $offset        = $data['start'];
        $search        = $data['search']['value'];
        $sortBy        = $data['order'][0]['dir'];
        $sortByIndex   = $data['order'][0]['column'];
        $searchColumns = $data['columns'];

        $params['search']        = $search;
        $params['searchColumns'] = $searchColumns;

        $totalCollection = $this->BaseRepository->getRecords($params, true);

        if ($totalCollection)
        {
            $params['sortByIndex'] = $sortByIndex;
            $params['sortBy']      = $sortBy;
            $params['limit']       = $limit;
            $params['offset']      = $offset;

            $collections = $this->BaseRepository->getRecords($params, false);
         
            foreach ($collections as $key => $collection)
            {
                $regNo = '';
                $cnt=0;
                foreach ($collection['registrations'] as $val)
                {
                    if($val['status'] == 'active')
                    {
                        if($cnt==0)
                        {
                            $regNo .= $val['vehicle_registration_number'];
                        }
                        else   
                        {
                            $regNo .= ','.$val['vehicle_registration_number'];
                        }  
                    }
                    
                    $cnt++;
                }
                $row = [];
                 /*
                CARPAR CLIENT ID
                */       
                $getClientId = $this->BaseRepository->geClientId($collection['id']);
                $srtClientID = '';
                $customer_active_subs = CustomerCarparkModel::leftJoin('carpark_subscription','carpark_subscription.id','customer_carpark.fk_carpark_subscription')->where('fk_customer_id',$collection['id'])->where('customer_carpark.status','active')->pluck('subscription_type')->all();

                if(!empty($getClientId) && sizeof($getClientId)>0)
                {
                    // $srtClientID = "<a href='" . url('customer/carparkClientEdit/' . $collection['id']) . "' class='' data-id='" . $collection["id"] . "' title='Add Carpark Client id'><span class='glyphicon glyphicon-eye-open'></span></a>";
                }
                else
                {
                    $srtClientID = "<a href='" . url('customer/carparkClientId/' . $collection['id']) . "' class='' data-id='" . $collection["id"] . "' title='Add Carpark Client id'><span class='fa fa-fw fa-plus'></span></a>";
                }

                $action = "

                    <a href='" . url('customer/edit/' . $collection['id']) . "' class='edit-user action-icon' data-id='" . $collection["id"] . "' title='Edit'><span class='glyphicon glyphicon-edit'></span></a>
                    <a href='" . url('customer/Customers_dashboard/' . $collection['id']) . "' class='comment-user action-icon' data-id='" . $collection["id"] . "' title='Dashboard'><span class='fa fa-dashboard'></span></a> ". $srtClientID."
                    <a href='".url('customer/link-account/'.$collection['id'])."'><span class='glyphicon glyphicon-random'></span>
                    </a>";

                // <a href='" . url('customer/sessionData/' . $collection['id']) . "' class='view-user action-icon' data-id='" . $collection["fk_customer_id"] . "' title='View Session Data'><span class='glyphicon glyphicon-paperclip'></span></a>
                if($collection['referral'] == 'new' && $collection['status']!='inactive') 
                {
                    $action .= "<a href='" . url('customers/new/referral/' . $collection['id']) . "' class='refer-user action-icon' title='Refer Credit To Customer'><span class='glyphicon glyphicon-level-up'></span></a>";
                    
                }
                
                array_push($row, $action);
                //array_push($row, $offset + ($key + 1));

                array_push($row, ucfirst($collection['first_name'].' '. $collection['last_name']));
                if($collection['status'] == 'new')
                {
                    array_push($row, 'Pending');
                }
                else if($collection['status'] == 'confirmed')
                {
                    array_push($row,'Active');
                }
                else
                {
                    array_push($row, ucfirst($collection['status']));
                }
                if($collection['corporate_name'] !='')
                {
                    array_push($row, $collection['corporate_name']);
                }
                else
                {
                    array_push($row,$collection['companyName']);
                }

                array_push($row, $collection['email']);
                array_push($row, $collection['client_no']);
                array_push($row, $regNo);
                array_push($row, $collection['is_carpark_requested']);
               

                

                /*if($collection['customer_type'] == '1' && !empty($collection['fk_corporate_id']))
                {
                    if($collection['status'] == 'inactive')
                    {
                        array_push($row, '3');//both
                    }
                    else
                    {
                        array_push($row, '0');//both
                    }
                    
                }
                else if(!empty($collection['fk_corporate_id']) && empty($collection['customer_type']))
                {
                    if($collection['status'] == 'inactive')
                    {
                        array_push($row, '3');//both
                    }
                    else
                    {
                        array_push($row,"2");//indi    
                    }
                    
                }
                else if(empty($collection['fk_corporate_id']) && empty($collection['customer_type']))
                {
                    if($collection['status'] == 'inactive')
                    {
                        array_push($row, '3');//both
                    }
                    else
                    {
                        array_push($row,"1"); //personal    
                    }
                    
                }*/
                if($collection['status'] == 'inactive')
                {
                    array_push($row,'3');
                }
                else
                {
                    if(in_array('personal', $customer_active_subs) &&in_array('corporate', $customer_active_subs))
                    {
                        array_push($row,'0');       
                    }
                    else if(in_array('personal', $customer_active_subs))
                    {
                        array_push($row,'1');          
                    }
                    else if(in_array('corporate', $customer_active_subs))
                    {
                        array_push($row,'2'); 
                    }
                }

                $columns[] = $row;
            }
        }

        $response = [
            'status'          => true,
            'draw'            => $page,
            'data'            => $columns,
            'recordsTotal'    => $totalCollection,
            'recordsFiltered' => $totalCollection
        ];

        return Response::json($response);
    }

    public function create()
    {
    	$this->ViewData['pageTitle'] 		 = 'Create '.$this->ModelTitle;
        $this->ViewData['form']['submit'] 	 = 'Create';
        $this->ViewData['form']['cancel'] 	 = str_plural($this->ModelPath);
        $this->ViewData['form']['link'] 	 = $this->ModelPath.'/create';
        $this->ViewData['form']['duplicate'] = url('check/customer/name');
        $this->ViewData['company'] 		     = $this->CommonRepository->getCompanyAll();
        $cities                              = CitiesModel::select('id','name','code','min_parking')->get();
        $this->ViewData['cities']            = $cities;
        $city_code = array();
        foreach ($cities as $key => $city) 
        {
            $data['id'] = $city['id'];
            $data['code'] = $city['code'];
            $data['min_parking'] = $city['min_parking']?$city['min_parking']:0;
            array_push($city_code,$data);
        }
        //dd($city_code);
        $this->ViewData['city_code']         = $city_code;
        $this->ViewData['corporate']         = $this->CommonRepository->getCorporateAll();
        $this->ViewData['objcarpark']        = $this->CommonRepository->getNoCarparkAll();
        $this->ViewData['city'] = '';
        $this->ViewData['city_id']='';
        $this->ViewData['min_parks'] = '';
        
        return view($this->ViewFolder.'create', $this->ViewData);
    }

    public function getcarparkWithSub(Request $request)
    {
        $recCarpark = '';
        $object     = $this->CommonRepository->getcarpark_corporate($request);

        $recCarpark .= '<option value="">Select Carpark</option>';
        foreach ($object->CSubscription as $obj)
        {

            $recCarpark.= '<option value="'.$obj['carpark']['id'].'" >'.$obj['carpark']['name'].'</option>';
        }
        return $recCarpark;
    }

    public function getSubscriptionRate(Request $request)
    {
        //dd($request->all());
        $arr_subscription   = array();
        $response           = $this->CommonRepository->getSubscriptionRateAll($request->subscriptionId);
        $corporate_details  = $this->BaseRepository->getCorporateEndDate($request);

        $arr_subscription['rate']          = $response->rate;
        $arr_subscription['commission']   = $response->commission;
        $arr_subscription['duration']      = $response->duration;
        $arr_subscription['duration_type'] = $response->duration_type;
        $arr_subscription['commission_duration_type'] = $response->commission_duration_type;
        $arr_subscription['payment_type']  = $response->payment_type;
        $arr_subscription['subscription_type']  = $response->subscription_type;
        $arr_subscription['vat_included']  = $response->vat_included;
        $arr_subscription['expiry_date']  = $corporate_details['to_date'];
        $arr_subscription['from_date']  = $corporate_details['from_date'];

        
        $effectiveDate = $this->BaseRepository->effectiveDate($request);
        $arr_date = array('effectiveDate'=> $effectiveDate);
        $expiry_date = array('expiry_date'=> $corporate_details);
        array_push($arr_subscription,$effectiveDate);
        return $arr_subscription;
    }

    public function store(Request $request)
    {
        //dd($request->all());
        $validator = Validator::make($request->all(),
        [
            'fname'       => 'required',
            'lname'       => 'required',
            'client_no'   => 'required|min:8',
            'email'       => 'required',
            'company_name'=> 'required',
            'status'      => 'required', 
            'maxreg'      => 'required|min:1|max:3',
        ]);

        if ($validator->fails())
        {
            return back()
                ->withErrors($validator)
                ->withInput();
        }

        // try
        // {
            if(!empty($request->prospective_customer_id))
            {
                $ProspectiveNewCustomerModel = ProspectiveNewCustomerModel::find($request->prospective_customer_id);
                $ProspectiveNewCustomerModel->status = 'accepted';
                $ProspectiveNewCustomerModel->updated_at = now();
                $ProspectiveNewCustomerModel->updated_by = auth()->user()->id;
                $ProspectiveNewCustomerModel->save();
            }
            $carparks = array();
            $all_curl_response = array();

            $response = $this->BaseRepository->create($request);
            
            if ($response['status'])
            {
                $carparks = array();
                /*ski data*/
                foreach($request->reg_no as $reg)
                {
                    if(!empty($reg))
                    {
                        foreach($request->carpark as $res)
                        {
                            $startDate  = str_replace('/', '-', $res['from_date']);
                            $from = date('Y-m-d',strtotime($startDate));

                            if(!empty($res['to_date']))
                            {
                                $endDate  = str_replace('/', '-', $res['to_date']);
                                $expiry_date = date('Y-m-d',strtotime($endDate));
                            }
                            else
                            {
                                $expiry_date = null;
                            }

                            /*$ski_carpark_details = CarParksModel::select('facility_no','ski_carpark_no')->where('id',$res['carpark'])->first();

                            $data['APIKey'] = $this->CommonRepository->getSkiApiKey();*/
                            /*$data['FacilityNo'] = $ski_carpark_details->facility_no;
                            $data['ValidCarparks'] =[$ski_carpark_details->ski_carpark_no];*/
                            /*$data['FacilityNo'] = '550012';
                            $data['ValidCarparks'] =[0];
                            $data['TicketNo'] =  str_replace(" ", '', trim($reg));
                            $data['TicketType'] = 4;
                            $data['ProductId'] = "PCPMT";
                            $data['ValidFrom'] = $from;
                            $data['ValidUntil'] = $expiry_date;
                            $data['ReferenceNo'] = str_replace(" ", '', trim($request->client_no));
                            $this->CommonRepository->makeCurlCallToSki('CreateSingleUseIdentifier',$data,'Create New Customer',$reg,$res['carpark'],$response['fk_customer_id']);*/
                        }
                    }
                }
                /* New park API */
            
                /* New park API end */
                if($response['invoiceId']!='')
                {
                    Session::put('refer_first',$response);
                    return redirect('corporates/sendMailWithSubject/'.$response['invoiceId'].'/customer');
                    
                }
                else
                {
                    return redirect(str_plural($this->ModelPath))
                   ->with(['refer' => $response]);
                }    
            }
            else
            {
            	return redirect(str_plural($this->ModelPath))
                    ->with(['error' => $response['message']])
                    ->withInput();
            } 
 
        // }
        // catch (\Exception $exception)
        // {
        //     Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
        //     return redirect(str_plural($this->ModelPath))
        //         ->with(['error' => Lang::get('custom.something_wrong')])
        //         ->withInput();
        // }
    }

    public function show($id)
    {
        //
    }

    public function edit($id)
    {
        if(!empty($refer_first))
        {
            Session::flash('refer', $refer_first); 
            session::put('refer_first','');
        }
        if(!empty($refer_st))
        {
            Session::flash('refer_st', $refer_st); 
            session::put('refer_st','');
        }
        $redirectPage = '';
        $redirectPage = Session::get('redirectPage');
        Session::put('redirectPage','');
        $this->ViewData['objcustomer']      = $this->BaseRepository->getCustomers($id);
        if (!empty($this->ViewData['objcustomer'])) {
            
        //update corporate customer count
       

        $this->ViewData['pageTitle']        = 'Edit '.$this->ModelTitle;
        $this->ViewData['form']['submit']   = 'Update';
        $this->ViewData['form']['link']     = $this->ModelPath.'/edit';
        $this->ViewData['form']['cancel']   = str_plural($this->ModelPath);
        $this->ViewData['form']['duplicate']= url('check/customer/name');
        $this->ViewData['company']          = $this->CommonRepository->getCompanyAll();
        $this->ViewData['cities']           = CitiesModel::select('id','name','code')->get();
        $this->ViewData['clientId']         = $this->BaseRepository->geClientId($id);
        $this->ViewData['ClientIdSub']      = $this->BaseRepository->geClientIdSub($id);
        $this->ViewData['corporate']        = $this->CommonRepository->getCorporateAll();
        $this->ViewData['objcustomer']      = $this->BaseRepository->getCollection($id);

        //dd($this->ViewData['objcustomer']);

        // /dd($this->ViewData['objcustomer']->city);
       
        $min_park_data = MinParkModel::select('is_overridden','min_park')->where('fk_customer_id',$id)->first();
       
        $reg_html = '';
        $reg_no = '';
        $i=1;
        $alter_reg_array = array();

        foreach ($this->ViewData['objcustomer']->registrations as $key => $reg_nos) 
        {
            $data[$reg_nos->vehicle_registration_number] = $reg_nos->alternate_reg_no; 
            $reg_html.='<div class="row reg"><div class="form-group col-md-3">
                            <label >Reg No '.$i.' 
                                <span class="required">*</span>
                            </label>
                            <input readonly required class="form-control" id="reg_'.$i.'" name="reg[]" value = "'.$reg_nos->vehicle_registration_number.'">
                        </div>';

            $reg_html.='<div class="form-group col-md-2">
                            <label for="maxreg"> 
                                </label>
                            &nbsp;&nbsp;
                            <div class="checkbox">
                                <input type="checkbox"  name="reg_check_'.$reg_nos->vehicle_registration_number.'" id="reg_check_'.$reg_nos->vehicle_registration_number.'" 
                                    ';

            if(!empty($reg_nos->alternate_reg_no)) 
            {
                $reg_html.='checked';
            }
            $alternate_regs = explode(",", $reg_nos->alternate_reg_no);
            //dd($alternate_regs);
            $reg_html.=' onclick="viewAlterRegTextbox(this,'.'\''.$reg_nos->vehicle_registration_number.'\''.','.$id.','.$i.')">Add Alternate Reg
                            </div>
                        </div>';
            
            if(!empty($reg_nos->alternate_reg_no))
            {
                //$alternate_regs = explode(',', $reg_nos->alternate_reg_no);
                $reg_html.='<div class="alter">';
                for ($i=0; $i < 3; $i++) 
                { 
                    if(!empty($alternate_regs[$i]))
                    {
                        $reg = $alternate_regs[$i];
                    }
                    else
                    {
                        $reg = '';
                    }
                    $reg_html.='<div class="form-group col-md-2">
                                    <label for="maxreg">Alternate Reg No '.($i+1).' 
                                        <span class="required">*</span>
                                    </label>
                                    <input required class="form-control" id="alternate_reg_'.$i.'" name="alter_reg['.$reg_nos->vehicle_registration_number.'][]" value = "'.$reg.'">
                                </div>'; 
                }
                $reg_html.='</div>';
            }

            $reg_html .= '</div>';

            array_push($alter_reg_array, $data);
            $i++;
        }
        //dd($data);
        $this->ViewData['real_reg_html'] = $reg_html;
        $this->ViewData['alternate_regs'] = $data;
        $this->ViewData['minparkdata'] = $min_park_data;
        $this->ViewData['requested_carparks']=$this->BaseRepository->getRequestedCarparks($id,false);

        $this->ViewData['rejected_carparks']=CustomerCarparkRequestModel::leftJoin('car_parks','car_parks.id','customer_carpark_request.fk_carpark_id')
        ->where('fk_customer_id',$id)->where('customer_carpark_request.status','rejected')->get();

        //dd($this->ViewData['requested_carparks']);

        $this->ViewData['actitvityLog']     = $this->BaseRepository->getCarparkActivity($id);  
        //dd($this->ViewData['actitvityLog']);
        $this->ViewData['objactivecustomer']= $this->BaseRepository->getActiveCollection($id);
        //dd($this->ViewData['objactivecustomer']);
        $this->ViewData['role']= $this->BaseRepository->getlogingDetail(Auth::id());
       
        $this->ViewData['id']               = $id;
        $this->ViewData['redirectPage']     = $redirectPage;
        if($this->ViewData['objcustomer']->fk_corporate_id!='')
        {
             $this->ViewData['corporateName']  = $this->CommonRepository->getCorporatename($this->ViewData['objcustomer']->fk_corporate_id);
        }
        else
        {
            $this->ViewData['corporateName'] = array();
        }
        $cities                              = CitiesModel::select('id','name','code','min_parking')
                                                            ->get();
        $this->ViewData['cities']            = $cities;
        $city_code = array();
        foreach ($cities as $key => $city) 
        {
            $data['id'] = $city['id'];
            $data['code'] = $city['code'];
            $data['min_parking'] = $city['min_parking']?$city['min_parking']:0;
            array_push($city_code,$data);
        }
       
        $this->ViewData['city_code'] = $city_code;
        
        /* check if the customer has linked account and if is master */
        $this->ViewData['is_master'] = false;
        $this->ViewData['is_account_linked'] = false;
        if(ClientAccountLinkModel::where('master_customer_id',$id)->exists())
        {
            $this->ViewData['is_account_linked'] = true;
            $this->ViewData['is_master'] = true;
        }
        else if(ClientAccountLinkModel::where('child_customer_id',$id)->exists())
        {
            $this->ViewData['is_account_linked'] = true;
        }

        $val         = Session::get('val');
        $sessionID   = Session::get('arr');
        $redirectVla = $ids = "";
        if($val!='' && $val == "corporate")
        {
            $redirectVla = "corporate";
        }
        if(!empty($sessionID))
        {
            $ids = $sessionID;
        }
        $this->ViewData['redirectVla']      = $redirectVla;
        $this->ViewData['ids']              = $ids;
        if($redirectVla == "corporate")
        {
            $this->ViewData['objcarpark']       = $this->CommonRepository->getCarparkAll();
        }
        else
        {
            $this->ViewData['objcarpark']       = $this->CommonRepository->getNoCarparkAll();
        }

        /*Linked Accounts */
        $master_id = ClientAccountLinkModel::where('child_customer_id',$id)->value('master_customer_id');
        if(!empty($master_id))
        {
            $linked_accounts = ClientAccountLinkModel::where('master_customer_id',$master_id)
                                                    ->where('child_customer_id','<>',$master_id)
                                                    ->pluck('child_pcp_client_id')
                                                    ->all();
        }
        else
        {
            $linked_accounts = [];
        }
        $this->ViewData['linked_accounts'] = $linked_accounts;
        
        session::put('refer_st','');
        session::put('refer_first','');
        return view($this->ViewFolder.'edit', $this->ViewData);
        }else {
          return redirect("customers")
              ->with(['error' => 'Customer not found']);
        } 
    }

    public function update(Request $request, $id)
    {
        //dd($request->all());
        $validator = Validator::make($request->all(),
        [
            'fname'       => 'required',
            'lname'       => 'required',
            'client_no'   => 'required',
            'email'       => 'required',
            'company_name'=> 'required',
            'status'      => 'required', 
            'maxreg'      => 'required|min:1|max:3',
        ]);

        if ($validator->fails())
        {
            return back()
                ->withErrors($validator)
                ->withInput();
        }

        // try
        // {
            /* Do not allow to make inactive if customer is master linked account */
            if($request->status == 'inactive')
            {
                if($request->is_master)
                {
                    if(!empty($request->linked_accounts))
                    {
                        if(count($request->linked_accounts) > 1)
                        {
                            return redirect(url('customer/edit/').$id)
                                    ->with(['error' => 'This is primary linked account. Please change primary account before inactivation'])
                                    ->withInput();
                        }
                    }
                }
            }
            
            $original_status = CustomersModel::where('id',$id)->value('status');
            $response = $this->BaseRepository->update($request,$id);
            if ($response['status'])
            {
                /* delete from ski if customer inactivated */
                /*if($request->status == 'inactive')
                {
                    $carparks = CustomerCarparkModel::join('car_parks','car_parks.id','customer_carpark.fk_carpark_id')
                                                ->where('fk_customer_id',$request->id)
                                                ->whereNotNull('facility_no')
                                                ->get(['facility_no','ski_carpark_no','from_date','expiry_date','fk_carpark_id']);

                    $client_no = CustomersModel::where('id',$id)->value('client_no');
                    foreach ($carparks as $key => $carpark) 
                    {
                        $data['APIKey'] = $this->CommonRepository->getSkiApiKey();*/
                        /*$data['FacilityNo'] = $carpark['facility_no'];*/
                        /*$data['FacilityNo'] = '550012';
                        $data['ReferenceNo'] = $client_no;
                        $this->CommonRepository->makeCurlCallToSki('DeleteIdentifier',$data,'Inactivate Customer','',0,$id);
                    }
                }*/
                /* pipedrive start */
                $user = CustomersModel::find($id);

                $pipedrive_id = $this->CommonRepository->userExistsInPipedriveByPCPId($user->client_no);

                //dd($pipedrive_id);
                //dd($request->status);
               
                if($pipedrive_id > 0)
                {
                    if($request->status == 'inactive')
                    {
                        $this->CommonRepository->deleteUserFromPipedrive($user,$pipedrive_id);
                    }
                    else
                    {
                        $org_id = $this->CommonRepository->checkIfOrgExists($user->fk_company_id);
                        if($org_id == 0)
                        {
                            $org_id = $this->CommonRepository->createOrgInPipeDrive($user->fk_company_id);
                        }
                     
                        $collect['id'] = $pipedrive_id;
                        $collect['name'] = $user->first_name.' '.$user->last_name;
                        if(!empty($user->email_second))
                        {
                            $collect['email'] = array($user->email,$user->email_second);
                        }
                        else
                        {
                            $collect['email'] = $user->email;
                        }
                        $collect['21dd5f74718901209387d27d3a95f0dc3f446d35'] = $user->client_no;
                        $collect['org_id'] = $org_id;

                        
                        if($user->status == 'confirmed')
                        {
                            //dd($collect);
                            if($original_status == 'inactive')
                            {
                                $collect['status'] = 'open';
                            }
                            else if($original_status == 'confirmed')
                            {
                                $collect['status'] = 'won';
                            }
                        }
                        else
                        {
                            //dd("no");
                            $collect['status'] = 'lost';
                        }

                        $this->CommonRepository->updateUserIntoPipedrive($collect);
                    }
                }

                return redirect(str_plural($this->ModelPath))
                    ->with(['success' => $response['message']]);
            }
            else
            {
                return redirect(str_plucompanyral($this->ModelPath))
                    ->with(['error' => $response['message']])
                    ->withInput();
            }

        // }
        // catch (\Exception $exception)
        // {
        //     Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
        //     return redirect(str_plural($this->ModelPath))
        //         ->with(['error' => Lang::get('custom.something_wrong')])
        //         ->withInput();
        // }
    }

    public function subupdate(Request $request, $id)
    {
        try
        {
            $response = $this->CommonRepository->subupdate($request, $id);
            //update corporate customer count
            //$this->CommonRepository->UpdateCorporateCustomerCount();
            if ($response['status'])
            {
                if($response['invoiceId']!='')
                {
                    return redirect('corporates/sendMailWithSubject/'.$response['invoiceId'].'/customeredit');
                }
                else
                {
                    return redirect("customer/edit/".$request->custid)
                    ->with(['success' => $response['message']]);
                }
               
            }
            else
            {
                 return redirect("customer/edit/".$request->custid)
                    ->with(['error' => $response['message']])
                    ->withInput();
            }

        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return redirect(str_plural($this->ModelPath))
                ->with(['error' => Lang::get('custom.something_wrong')])
                ->withInput();
        }
    }

    public function subadd(Request $request, $id)
    {
        
        // try
        // {
            if(!empty($sessionID))
            {
                $ids = $sessionID;
                $redirectVla = $ids = "";
                $redirect = "corporate/viewCustomer/".$ids;
            }
            else
            {
                $ids  = $id;
                $redirect = "customer/edit/".$id;
            }
            $response = $this->CommonRepository->subadd($request, $ids,true);
            
            if ($response['status'])
            {
                /* add customer all reg to ski for this carpark if active */
                /*
                    array:5 [▼
                      "_token" => "a233BRjEoqNa2fgfzM69Xi4g1ddCvaLNXDbug1uq"
                      "carpark" => array:1 [▼
                        1 => array:8 [▼
                          "carpark" => "2"
                          "subscription" => "11"
                          "rate" => "6.900000"
                          "vat" => "Yes"
                          "duration" => "1 day"
                          "commission" => "3.833300"
                          "from_date" => "07/01/2022"
                          "to_date" => null
                        ]
                      ]
                      "hd_carpark_2" => "2"
                      "hidden-rate" => "6.900000"
                      "hidden-commission" => "3.833300"
                    ]
                */
                /*$carparks = $request->carpark;
                foreach ($carparks as $key => $carpark) 
                {
                   $details = CarParksModel::select('facility_no','ski_carpark_no')
                                            ->where('id',$carpark['carpark']) 
                                            ->whereNotNull('facility_no')
                                            ->first();
                    if(!empty($details))
                    {
                        $client_no = CustomersModel::where('id',$ids)->value('client_no');
                        $startDate   = str_replace('/', '-', $carpark['from_date']);
                        $from_date   = date('Y-m-d',strtotime($startDate));
                        if(!empty($carpark['to_date']))
                        {
                            $endDate  = str_replace('/', '-', $carpark['to_date']);
                            $expiry_date = date('Y-m-d',strtotime($endDate));
                        }
                        else
                        {
                            $expiry_date = null;
                        }*/
                        /* add to ski only if active subscription */
                        /*if((strtotime($from_date)==strtotime(date('Y-m-d'))) || (strtotime($from_date)<strtotime(date('Y-m-d')) && empty($expiry_date)) ||(strtotime($from_date)<strtotime(date('Y-m-d')) && strtotime($expiry_date)>=strtotime(date('Y-m-d'))))
                        {
                            $reg_numbers = CustomerVehicalRegModel::where('fk_customer_id',$ids)
                                                                ->where('status','active')
                                                                ->pluck('vehicle_registration_number')
                                                                ->all();

                            foreach ($reg_numbers as $key => $reg) 
                            {

                                $data['APIKey'] = $this->CommonRepository->getSkiApiKey();*/
                                /*$data['FacilityNo'] = $details->facility_no;
                                $data['ValidCarparks'] =[$details->ski_carpark_no];*/
                               /* $data['FacilityNo'] = '550012';
                                $data['ValidCarparks'] =[0];
                                $data['TicketNo'] =  $reg;
                                $data['TicketType'] = 4;
                                $data['ProductId'] = "PCPMT";
                                $data['ValidFrom'] = $from_date;
                                $data['ValidUntil'] = $expiry_date;
                                $data['ReferenceNo'] = $client_no;
                                $this->CommonRepository->makeCurlCallToSki('CreateSingleUseIdentifier',$data,'Add New Subscription',$reg,$carpark['carpark'],$ids);
                            }
                        }
                    }
                }*/
                /* ski end */
                /* update the deal for carpark in pipedrive */
                $customer   = CustomersModel::find($ids);
                $user['name'] = $customer->first_name;
                $user['person_id'] = $this->CommonRepository->userExistsInPipedriveByPCPId($customer->client_no);
                $org_id = $this->CommonRepository->checkIfOrgExists($customer->fk_company_id);
                if($org_id == 0)
                {
                    $org_id = $this->CommonRepository->createOrgInPipeDrive($customer->fk_company_id);
                }
                $user['organization_id'] = $org_id;
                $carparks = CustomerCarparkModel::where('fk_customer_id',$customer->id)->where('status','active')->pluck('fk_carpark_id')->all();
                if($user['person_id']>0)
                {
                    $deal_id = $this->CommonRepository->searchDeal($user);
                    if($deal_id > 0 && count($carparks)>0)
                    {
                        $car_parks = array();
                        foreach ($carparks as $key => $id) 
                        {
                            $deal_field_id = CarParksModel::where('id',$id)->value('deal_field_id');
                            array_push($car_parks,$deal_field_id);
                        }
                        $deals = $this->pipe_drive_client->getDeals();
                        $collect['id'] = $deal_id;
                        //$collect['13f8225eba35f22dc11c85ec34b2260a367b5d84'] = $car_parks;//test
                        $collect['8848c268d846bfab155eef5df6edcdaad2ff7feb'] = $car_parks;//live
                        $result = $deals->updateADeal($collect);
                        $this->CommonRepository->addCorporateOnlyNote($ids,$deal_id);
                    }
                }
                if($response['invoiceId']!='')
                {
                    return redirect('corporates/sendMailWithSubject/'.$response['invoiceId'].'/customer');
                }
                else
                {
                    return redirect($redirect)
                    ->with(['success' => $response['message']]);
                }
                 
            }
            else
            {
                $val         = Session::get('val','');
                $sessionID   = Session::get('id','');
                return redirect("customer/edit/".$id)
                    ->with(['error' => $response['message']])
                    ->withInput();
            }      
        // }  
        // catch (\Exception $exception)
        // {
        //     //dd($exception->getMessage());
        //     Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
        //     return redirect(str_plural($this->ModelPath))
        //         ->with(['error' => Lang::get('custom.something_wrong')])
        //         ->withInput();
        // }
    }

    public function UpdateRequestedCarparkStatus(Request $request)
    {

        $customer_id = trim($request->customer_id);
        $carpark_id = trim($request->carpark);
        $sub_id = trim($request->subscription);
        $status = trim($request->status);
        $new_request= new Request;
        //$carpark=array();
        $carpark[0]['carpark'] = $carpark_id;
        $carpark[0]['subscription']=$sub_id;
        $carpark[0]['rate']=$request->rate;
        $carpark[0]['from_date']=$request->from_date;
        $carpark[0]['to_date']=$request->to_date;
        $carpark[0]['commission']=$request->commission;

       /* $new_request->carpark= array(
            'carpark'=>$carpark_id,
            'subscription'=>$sub_id,
            'rate'=>$request->rate,
            'from_date'=>$request->from_date,
            'to_date'=>$request->to_date
        );*/

        $new_request->carpark = $carpark;

        //dd($new_request->carpark['carpark']);
        
        if($status == 'rejected' || $status == 'accepted')
        {
            CustomerCarparkRequestModel::where('fk_customer_id',$customer_id)
                                        ->where('fk_carpark_id',$carpark_id)
                                        ->where('status','requested')
                                        ->update([
                                            'status'=>$status,
                                            'updated_at'=>now()
                                        ]);

            $requested_carpark_count = 
            CustomerCarparkRequestModel::where('fk_customer_id',$customer_id)
                                        ->where('status','requested')->count();
            if($requested_carpark_count == 0)
            {
                CustomersModel::where('id',$customer_id)
                                ->update([
                                    'is_carpark_requested'=>'no'
                                ]);
            }
        }
        if($status == 'rejected')
        {
            return url("customer/edit/".$customer_id);
        }
        else if($status == 'accepted')
        {
            if(!empty($sessionID))
            {
                $ids = $sessionID;
                $redirectVla = $ids = "";
                $redirect = "corporate/viewCustomer/".$ids;
            }
            else
            {
                $ids  = $customer_id;
                $redirect = "customer/edit/".$customer_id;
            }


            
            $response = $this->CommonRepository->subadd($new_request, $ids,true);
            
            if ($response['status'])
            {
                
                if($response['invoiceId']!='')
                {
                    return url('corporates/sendMailWithSubject/'.$response['invoiceId'].'/customer');
                }
                else
                {
                    return url($redirect);
                }
                 
            }
            else
            {
                $val         = Session::get('val','');
                $sessionID   = Session::get('id','');
                return redirect("customer/edit/".$id)
                    ->with(['error' => $response['message']])
                    ->withInput();
            }      

        }

    }
    public function checkDuplicate(Request $request)
    {
        $validator = Validator::make($request->all(),
        [
            'client_no'  => 'required',
        ]);

        if ($validator->fails())
        {
            return 'false';
        }
        try
        {
            $customer = isset($request->customer) ? $request->customer : 0;
            $isExist = $this->BaseRepository->checkDuplicate($request->client_no,$customer);
            
            return $isExist ? 'false' : 'true';
        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return 'false';
        }
    }

    public function checkDuplicateClientId(Request $request)
    {
        try
        {
            $customer = isset($request->customer) ? $request->customer : 0;
            $isExist = $this->BaseRepository->checkDuplicateClientId($request,$customer);
            if($isExist == 0)
            {
                return 'false';
            }
            else
            {
                return $isExist;
            }
        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return 'false';
        } 
    }

    public function removeClientId(Request $request)
    {
        try
        {
            $isExist = $this->BaseRepository->removeClientId($request);
            if($isExist == 0)
            {
                return 'false';
            }
            else
            {
                return 'true';
            }
        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return 'false';
        }
    }
    public function registrationNumberDuplication(Request $request)
    {
        $customer = isset($request->customer) ? $request->customer : 0;
        $isExist = $this->BaseRepository->registrationNumberDuplication($request);
        return $isExist;
    }

    public function checkDublicatRegNo(Request $request)
    {
        try
        {
            $customer = isset($request->customer) ? $request->customer : 0;
            $isExist = $this->BaseRepository->checkDuplicateRegno($request->new_reg_no,$customer);

            if($isExist == 0)
            {
                return "true";
            }
            else
            {
                return "false";
            }

        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return 'false';
        }
    }

    public function regCount(Request $request)
    {
        try
        {
            $isExist = $this->BaseRepository->regCount($request);
            if((int)$isExist >= (int)2 && (int)$isExist < (int)3)
            {
                return 1;
            }
            else
            {
                return 0;
            }
        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return 'false';
        }
    }

    public function checkDuplicatecust(Request $request)
    {
       
        $validator = Validator::make($request->all(),
        [
            'client_no'  => 'required',
        ]);

        if ($validator->fails())
        {
            return 'false';
        }
        try
        {
            $customer = isset($request->customer) ? $request->customer : 0;
            $isExist = $this->BaseRepository->checkDuplicate($request->cust_client_no,$customer);
            return $isExist ? 'false' : 'true';
        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return 'false';
        }
    }

    public function removerec(Request $request)
    {
        $response = $this->CommonRepository->custdestroy($request);
        return response()->json($response);
    }
    public function comment($id)
    {

        $loginId = Auth::id();
        $redirectPage = '';
        $redirectPage = Session::get('redirectPage');
        Session::put('redirectPage','');

        $this->ViewData['redirectPage']     = $redirectPage;
        $this->ViewData['pageTitle']        = 'Manage Comment';
        $this->ViewData['form']['submit']   = 'comment';
        $this->ViewData['form']['link']     = $this->ModelPath.'/comment';
        $this->ViewData['form']['cancel']   = str_plural($this->ModelPath);
        $this->ViewData['id']               = $id;
        $this->ViewData['loginId']          = $loginId;
        $this->ViewData['object']           = $this->CommonRepository->getComment($id);
        $this->ViewData['collection']       = $this->BaseRepository->customerDetails($id);
        $this->ViewData['carparks']         = $this->CommonRepository->getSubscriptionWithCarparks($id);

        if(!empty($this->ViewData['collection'])){
        return view($this->ViewFolder.'comment', $this->ViewData);
        }else {
          return redirect("customers")
              ->with(['error' => 'Customer not found']);
        } 
    }

    public function addComment(Request $request)
    {
        $validator = Validator::make($request->all(),
        [
            'add_comment'       => 'required',
        ]);

        if ($validator->fails())
        {
            return back()
                ->withErrors($validator)
                ->withInput();
        }

        try
        {
            $loginId = Auth::id();
            $response = $this->BaseRepository->createComment($request,$loginId);

            if ($response['status'])
            {
                /* add comment in pipedrive */
                $customer = CustomersModel::find($request->customerId);
                $user['name'] = $customer->first_name;
                $user['person_id'] = $this->CommonRepository->userExistsInPipedriveByPCPId($customer->client_no);
                
                $deal_id = $this->CommonRepository->searchDeal($user);
                
                if($deal_id > 0)
                {
                    $this->CommonRepository->addCommentInPipedrive($deal_id,$request->add_comment);
                }                
                if(Session::get('customerDashboard')!='')
                {
                    return redirect('customer/Customers_dashboard/'.$request->customerId)
                    ->with(['success' => $response['message']]);
                }
                else
                {
                    return redirect('customer/Customers_dashboard/'.$request->customerId)
                    ->with(['success' => $response['message']]);
                }

            }
            else
            {
                if(Session::get('customerDashboard')!='')
                {

                }
                else
                {
                    return redirect(str_plural($this->ModelPath))
                    ->with(['error' => $response['message']])
                    ->withInput();
                }

            }

        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return redirect(str_plural($this->ModelPath))
                ->with(['error' => Lang::get('custom.something_wrong')])
                ->withInput();
        }
    }

    public function editComment(Request $request)
    {
        try
        {
            $response = $this->CommonRepository->updateComment($request);

            if ($response['status'])
            {
                return $response;
            }
            else
            {
                return "Comment Not updated.";
            }

        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());

            dd($exception->getMessage());
            // return redirect(str_plural($this->ModelPath))
            //     ->with(['error' => Lang::get('custom.something_wrong')])
            //     ->withInput();

            $this->Rresponse['status'] = false;
            $this->Rresponse['message'] = 'Something went wrong, Please try again later.';
            return $this->Rresponse;
        }
    }

    public function view($id)
    {
        $loginId = Auth::id();
        $this->ViewData['pageTitle']        = 'View '.$this->ModelTitle;
        $this->ViewData['objcustomer']      = $this->BaseRepository->getCollection($id);
        if($this->ViewData['objcustomer']->fk_company_id!='')
        {
            $this->ViewData['company']     = $this->CommonRepository->getCompanyname($this->ViewData['objcustomer']->fk_company_id);
        }
        else
        {
            $this->ViewData['company']     = array();
        }
       
        if($this->ViewData['objcustomer']->fk_corporate_id!='')
        {
            $this->ViewData['corporate']   = $this->CommonRepository->getcorporateName($this->ViewData['objcustomer']->fk_corporate_id);
        }
        else
        {
            $this->ViewData['corporate']    = array();
        }
        $this->ViewData['object']           = $this->CommonRepository->getComment($id);

        return view($this->ViewFolder.'view', $this->ViewData);
    }

    public function sessionData($id)
    {
        $this->ViewData['pageTitle']      = 'Manage Imported Sessions';
        $this->ViewData['link']['load']   = url('load/customer/session');
        $this->ViewData['customerId']    = $id;
        $this->ViewData['collection']       = $this->BaseRepository->customerDetails($id);

        return view($this->ViewFolder.'session', $this->ViewData);
    }

    public function historical_overlays($id)
    {
        $this->ViewData['pageTitle']      = 'Historical Overlays';
        $this->ViewData['link']['load']   = url('load/customer/historical_overlays');
        $this->ViewData['customerId']    = $id;
        $this->ViewData['collection']    = $this->BaseRepository->customerDetails($id);

        return view($this->ViewFolder.'historicalOverlays', $this->ViewData);
    }

    public function getHistoricalOverlaysRecords(Request $request)
    {
        $data = $request->all();

        $columns       = [];
        $page          = $data['draw'];
        $limit         = $data['length'];
        $offset        = $data['start'];
        $search        = $data['search']['value'];
        $sortBy        = $data['order'][0]['dir'];
        $sortByIndex   = $data['order'][0]['column'];
        $searchColumns = $data['columns'];

        $params['search']        = $search;
        $params['searchColumns'] = $searchColumns;
        $params['customerid']    = $request->customerid;

        $totalCollection = count($this->BaseRepository->getHistoricalOverlaysRecords($params,$request->customerid));
        if ($totalCollection)
        {
            $params['sortByIndex'] = $sortByIndex;
            $params['sortBy'] = $sortBy;
            $params['limit'] = $limit;
            $params['offset'] = $offset;

            $collections = $this->BaseRepository->getHistoricalOverlaysRecords($params,$request->customerid);

            foreach ($collections as $key => $collection)
            {
                $row = [];
                array_push($row, $offset + ($key + 1));
                array_push($row, ucfirst($collection['carpark_name']));
                array_push($row, $collection['month']);
                array_push($row, $collection['year']);
                array_push($row, $collection['total_visit'] );
                array_push($row, $collection['invoice_ref_no']);
                $columns[] = $row;
            }
        }

        $response = [
            'status' => true,
            'draw' => $page,
            'data' => $columns,
            'recordsTotal' => $totalCollection,
            'recordsFiltered' => $totalCollection
        ];

        return Response::json($response);
    }

    public function getSessionRecords(Request $request)
    {
        $data = $request->all();

        $columns       = [];
        $page          = $data['draw'];
        $limit         = $data['length'];
        $offset        = $data['start'];
        $search        = $data['search']['value'];
        $sortBy        = $data['order'][0]['dir'];
        $sortByIndex   = $data['order'][0]['column'];
        $searchColumns = $data['columns'];

        $params['search']        = $search;
        $params['searchColumns'] = $searchColumns;
        $params['customerid']    = $request->customerid;

        $totalCollection = count($this->BaseRepository->getSessionRecords($params,$request->customerid));
        if ($totalCollection)
        {
            $params['sortByIndex'] = $sortByIndex;
            $params['sortBy'] = $sortBy;
            $params['limit'] = $limit;
            $params['offset'] = $offset;

            $collections = $this->BaseRepository->getSessionRecords($params,$request->customerid);

            foreach ($collections as $key => $collection)
            {
                $row = [];
                array_push($row, $offset + ($key + 1));
                array_push($row, ucfirst($collection['carpark_name']));
                array_push($row, ucfirst($collection['fk_vehicle_registration_number']));
                array_push($row, $collection['parking_date']);
                array_push($row, $collection['in_time'] );
                array_push($row, $collection['out_time']);
                array_push($row, $collection['reload_comment']);
                $columns[] = $row;
            }
        }

        $response = [
            'status' => true,
            'draw' => $page,
            'data' => $columns,
            'recordsTotal' => $totalCollection,
            'recordsFiltered' => $totalCollection
        ];

        return Response::json($response);
    }

    public function importCSV()
    {
        $this->ViewData['pageTitle']     = 'Import Customers Data';
        $this->ViewData['link']['load']  = url('customer/importCSV');
        return view($this->ViewFolder.'importfile', $this->ViewData);
    }
    public function uploadfile(Request $request)
    {
       
        $validator = Validator::make(
        [
            'file'      => $request->file,
            'extension' => strtolower($request->file->getClientOriginalExtension()),
        ],
        [
            'file'          => 'required',
            'extension'      => 'required|in:csv,xlsx,xls,xlsm',
        ]);

        if ($validator->fails())
        {
            $response = [];
            $response['status'] = false;
            $response['message'] = 'Only file type xls/xlsx/xlsm/csv is allowed';
            return $response;
        }
        else
        {
            return $this->BaseRepository->importInactivationDate($request); 
            // return $this->BaseRepository->importReferralCredit($request);
            // return $this->BaseRepository->importcorporateCustomer($request);  
            //return $this->BaseRepository->importCustomer_bkp($request);   
            //return $this->BaseRepository->importCompany($request);
            //return $this->BaseRepository->importCustomerSubscription($request);   
            //return $this->BaseRepository->importCarparkSubscription($request);       
            //return $this->BaseRepository->importCarparkSubscriptionHistory($request); 
            //return $this->BaseRepository->importCorporate($request);   
            //return $this->BaseRepository->importCorporateSubscription($request); 
            //return $this->BaseRepository->importCorporateSubscriptionPaygHistory($request);
            //return $this->BaseRepository->importcorporateCustomer($request);   
            //return $this->BaseRepository->importcorporateCustomerSubscription($request);    
            //return $this->BaseRepository->importindividualCustomer($request);  
            //return $this->BaseRepository->importCustomerSubscription($request);
            //return $this->BaseRepository->importCustomerSubscription($request); 
            //return $this->BaseRepository->importDummyCorporate($request); 
            //return $this->BaseRepository->importCustomerSubscriptionProd($request); 
            //return $this->BaseRepository->importCreditRedeem($request);      
            //return $this->BaseRepository->importindividualCustomerPaymantType($request);
            //return $this->BaseRepository->importCorporatePaymant_type($request);
            //return $this->BaseRepository->importCustomerSubscriptionSpecialRate($request);
            //return $this->BaseRepository->importCustomerCarpark($request);
            //return $this->BaseRepository->importindividualRegiNumber($request);
            //return $this->BaseRepository->importUniqueId($request);
            //return $this->BaseRepository->importCarparkClientNumber($request);
            //return $this->BaseRepository->importCarparkClientIdProd($request);
            // return $this->BaseRepository->updateXeroContacts($request);
            // return $this->BaseRepository->importCustomerhistorySubscription($request);
            //return $this->BaseRepository->importCustomerhistoryYPS($request); 
            //return $this->BaseRepository->importCustomerSubscriptionProd($request);

            //return $this->BaseRepository->importCustomerhistoryYPS($request);
            //return $this->BaseRepository->importWrongSubscriptionchangeTocorrect($request);
            //return $this->BaseRepository->importchangeCarparkSubscriptioncommision($request);
            
            //return $this->BaseRepository->bad_subscription($request);
            //return $this->BaseRepository->corporate_subscription($request);
            //return $this->BaseRepository->importCustomerHistory($request);
            //return $this->BaseRepository->car_park_subscription_commission_date($request);
            // return $this->BaseRepository->importCustomerHistory($request);
            // return $this->BaseRepository->importCustomerHistory($request);
            // return $this->BaseRepository->importYpsHistory($request); 
            //return $this->BaseRepository->importCropStartDateLeveaDate($request);
            //--------
            //return $this->BaseRepository->importSessionDataWithdate($request);
            //return $this->BaseRepository->importSubscriptiondate($request); 
            //return $this->BaseRepository->importSubscriptiondateHistory($request); 
            //return $this->BaseRepository->importCustomeractivityLog($request); 
            //return $this->BaseRepository->importPermission($request);              //----
            //return $this->BaseRepository->importCustomerCarparkNew($request);
            //return $this->BaseRepository->importCustomerCarparkNewCancel($request);
            //return $this->BaseRepository->importCorporateStartDate($request);
            //return $this->BaseRepository->importCustomerCarparkNewYPS($request);
            //return $this->BaseRepository->importCustomerCarparkCancelYPS($request);
            //return $this->BaseRepository->importCorporateSubscriptionNew($request);
            //return $this->BaseRepository->importCorporatedowngrate($request);
            //return $this->BaseRepository->importCorporateMember($request);
            //return $this->BaseRepository->importCustomerSubscriptionupdatestatus($request);
            //return $this->BaseRepository->importupdateLastDate($request);
            //$this->BaseRepository->importCorporateSubscriptionNew($request);
            //return $this->BaseRepository->importnewProdCustomer($request);  
            /*
            |   SHESH CREDIT IMPORT NEW
            */
                // return $this->BaseRepository->importCredit($request);  
                // return $this->BaseRepository->importCreditParking($request); 
                // return $this->BaseRepository->compareSkippedTransactions($request); 
            /*
            | END
            */
        }
    }
    public function vehicleDetails($id)
    {
        $loginId = Auth::id();
        $redirectPage = '';      
        $redirectPage = Session::get('redirectPage');
       
        Session::put('redirectPage','');
        $this->ViewData['pageTitle']        = 'Registration Number';
        $this->ViewData['form']['submit']   = 'vehicalReg';
        $this->ViewData['form']['link']     = $this->ModelPath.'/vehicalReg';
        $this->ViewData['form']['cancel']   = str_plural($this->ModelPath);
        $this->ViewData['id']               = $id;
        $this->ViewData['redirectPage']     = $redirectPage;
        $this->ViewData['collection']       = $this->BaseRepository->customerDetails($id);
        
        
        if (!empty($this->ViewData['collection'])) {
        return view($this->ViewFolder.'registrationNo', $this->ViewData);
        }else {
          return redirect("customer/Customers_dashboard/".$id)
              ->with(['error' => 'Customer not found']);  
        } 
    }
    public function customerReg(Request $request, $id)
    {
        $data = $request->all();
        $columns       = [];
        $page          = $data['draw'];
        $limit         = $data['length'];
        $offset        = $data['start'];
        $search        = $data['search']['value'];
        $sortBy        = $data['order'][0]['dir'];
        $sortByIndex   = $data['order'][0]['column'];
        $searchColumns = $data['columns'];

        $params['search']        = $search;
        $params['searchColumns'] = $searchColumns;

        $totalCollection = count($this->CommonRepository->getRegistration($params,$id));

        if ($totalCollection)
        {
            $params['sortByIndex'] = $sortByIndex;
            $params['sortBy'] = $sortBy;
            $params['limit'] = $limit;
            $params['offset'] = $offset;

            $collections = $this->CommonRepository->getRegistration($params,$id);
            
            //dd($collections);
            foreach ($collections as $key => $collection)
            {
                $row = [];
                array_push($row, $offset + ($key + 1));
                array_push($row, strtoupper($collection['vehicle_registration_number']));
                array_push($row, strtoupper($collection['alternate_reg_no']));
                array_push($row, date('d-m-Y',strtotime($collection['created_at'])));
                array_push($row, $collection['deleted_at']?date('d-m-Y',strtotime($collection['deleted_at'])):'');
                if ($collection['status'] == 'active')
                {
                    $activeCheckbox = '<input type="checkbox" id="user-' . $collection['id'] . '" name="user-status" checked="checked" class="user-status" data-size="mini" data-id="' . $collection['id'] . '">';
                }
                else
                {
                    $activeCheckbox = '<input type="checkbox" id="user-' . $collection['id'] . '" name="user-status" data-size="mini" class="user-status" data-id="' . $collection['id'] . '">';
                }
                array_push($row, $activeCheckbox);
                $action = "
                     <a href='" . url('customer/customerRegNoEdit/'. $collection['id']) . "' class='edit-user action-icon' data-id='" . $collection["id"] . "' title='Edit'><span class='glyphicon glyphicon-edit'></span></a>&nbsp;&nbsp;";
                /*if(empty($collection['alternate_reg_no']))
                {*/    $alternate_reg_no = explode(',', $collection['alternate_reg_no']);
                    $alter_1 = $alternate_reg_no[0]??'';
                    $alter_2 = $alternate_reg_no[1]??'';
                    $alter_3 = $alternate_reg_no[2]??'';
                    $action.= "<a class='add-user action-icon' data-id='" . $collection["id"] . "' title='Add alternate'><span class='glyphicon glyphicon-pencil' data-toggle='modal' data-target='#myModal_".$collection['id']."'></span>
                        <div id='myModal_".$collection['id']."' class='modal fade' role='dialog'>
                          <div class='modal-dialog'>

                            <!-- Modal content-->
                            <div class='modal-content'>
                              <div class='modal-header'>
                                <h4 class='modal-title'>Add/Edit Alternate Reg for ".strtoupper($collection['vehicle_registration_number'])."</h4>
                              </div>
                              <div class='modal-body'>
                              <div class='row'>
                                <div class='form-group col-md-3'>
                                    <label>Alternate Reg 1</label>
                                    <input type='text' required class='form-control' name='alter_1' id='alter_1_".$collection['id']."' value='".$alter_1."'>&nbsp;
                                </div>
                                <div class='form-group col-md-3'>
                                    <label>Alternate Reg 2</label>
                                    <input type='text' required class='form-control' name='alter_2' id='alter_2_".$collection['id']."' value='".$alter_2."'>&nbsp;
                                </div>
                                <div class=' form-group col-md-3'>
                                    <label>Alternate Reg 3</label>
                                    <input type='text' required class='form-control' name='alter_3' id='alter_3_".$collection['id']."' value='".$alter_3."'>
                                </div>
                              </div>
                              </div>
                              <div class='modal-footer'>
                                <button type='button' class='btn btn-default' data-dismiss='modal'>Close</button>
                                <button type='button' class='btn btn-primary save-alternate-reg' onclick=saveAlternate(".$collection['id'].")>Save</button>
                              </div>
                            </div>

                          </div>
                        </div>
                     </a>
                     ";
                //}
                array_push($row, $action);

                $columns[] = $row;
            }
        }

        $response = [
            'status' => true,
            'draw' => $page,
            'data' => $columns,
            'recordsTotal' => $totalCollection,
            'recordsFiltered' => $totalCollection
        ];

        return Response::json($response);
    }
    public function RegNoEdit($id)
    {
        $loginId = Auth::id();
        $this->ViewData['pageTitle']  = 'Edit Registration Number';
        $this->ViewData['collection'] = $this->BaseRepository->getRegNumber($id);

        $this->ViewData['id']         =$id;
        return view($this->ViewFolder.'regiNoEdit', $this->ViewData);
    }

    public function RegNoUpdate(Request $request,$id)
    {
        // dd($request->all(),$id);
        try
        {
            $checkId = $this->BaseRepository->getRegNumber($id);
           
            if(!empty($checkId))
            {
                
                $update = $this->BaseRepository->RegNumberUpdate($request,$id);
          
                if($update == true)  
                {
                    /*$customer_id = CustomerVehicalRegModel::where('id',$id)->value('fk_customer_id');
                    $client_no = CustomersModel::where('id',$customer_id)->value('client_no');
                    
                    $carparks =  CustomerCarparkModel::join('car_parks','car_parks.id','customer_carpark.fk_carpark_id')
                    ->where('fk_customer_id',$customer_id)
                    ->where('customer_carpark.status','active')
                    ->WhereNotNull('facility_no')
                    ->get(['fk_carpark_id','facility_no','ski_carpark_no','from_date','expiry_date']);*/

                    /*if(!$carparks->isEmpty())
                    {
                        foreach ($carparks as $key => $res) 
                        {
                            $data['APIKey']         = $this->CommonRepository->getSkiApiKey();*/
                            /*$data['FacilityNo']   = $res['facility_no'];*/
                            /*$data['FacilityNo']     = '550012';
                            $data['ReferenceNo']    = $client_no;

                            $this->CommonRepository->makeCurlCallToSki('DeleteIdentifier',$data,'Reg Update.',$reg,$res['fk_carpark_id'],$request->custID);
                        }
                        $reg_numbers = CustomerVehicalRegModel::where('fk_customer_id',$customer_id)->where('status','active')->pluck('vehicle_registration_number')->all();
                        foreach ($reg_numbers as $key => $reg) 
                        {
                            foreach ($carparks as $key => $res) 
                            {
                                $data['APIKey']         = $this->CommonRepository->getSkiApiKey();*/
                                /*$data['FacilityNo']   = $res['facility_no'];
                                $data['ValidCarparks']  = [$res['ski_carpark_no']];*/
                                /*$data['FacilityNo']     = '550012';
                                $data['ValidCarparks']  = [0];
                                $data['TicketNo']       = $reg;
                                $data['TicketType']     = 4;
                                $data['ProductId']      = "PCPMT";
                                $data['ValidFrom']      = $res['from_date'];
                                $data['ValidUntil']     = $res['expiry_date'];
                                $data['ReferenceNo']    = $client_no;

                                $this->CommonRepository->makeCurlCallToSki('CreateSingleUseIdentifier',$data,'Reg Update.',$reg,$res['fk_carpark_id'],$customer_id);   
                            }
                        }
                    }    */
                    $this->Rresponse['status']  = true;
                    $this->Rresponse['message'] = 'Registration number updated successfully.'; 
                }
            }
            else
            {
                $this->Rresponse['status']  = true;
                $this->Rresponse['message'] = 'Registration number not updated successfully.'; 
            }
            return $this->Rresponse;

        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return $exception->getMessage();
        }
    }
    public function updateUserStatus(Request $request)
    {
        
        try
        {
            $flag = "";
            if($request->status == "true")
            {
                $getRec = $this->CommonRepository->chkstatusRegNumber($request->custID);
                $status = "inactive";
            }
            else
            {
                $getRec = "yes";
                $status = "active";
            }

            if($getRec == "yes")
            {
                $response = $this->CommonRepository->updateStatus($request->id,$status);
                
                if ($response['status'] && (empty($request->effective_from) || strtotime($request->effective_from) == strtotime(date('d/m/Y'))))
                {
                    /* add reg in ski */
                    /*$client_no = CustomersModel::where('id',$request->custID)->value('client_no');
                    
                    $carparks =  CustomerCarparkModel::join('car_parks','car_parks.id','customer_carpark.fk_carpark_id')
                    ->where('fk_customer_id',$request->custID)
                    ->where('customer_carpark.status','active')
                    ->WhereNotNull('facility_no')
                    ->get(['fk_carpark_id','facility_no','ski_carpark_no','from_date','expiry_date']);
                    
                    $reg = CustomerVehicalRegModel::where('id',$request->id)->value('vehicle_registration_number');*/

                    /*if(!$carparks->isEmpty())
                    {
                        if($request->status == "true")
                        {
                            foreach ($carparks as $key => $res) 
                            {
                                $data['APIKey']         = $this->CommonRepository->getSkiApiKey();*/
                                /*$data['FacilityNo']   = $res['facility_no'];
                                $data['ValidCarparks']  = [$res['ski_carpark_no']];*/
                                /*$data['FacilityNo']     = '550012';
                                $data['ValidCarparks']  = [0];
                                $data['TicketNo']       = $reg;
                                $data['TicketType']     = 4;
                                $data['ProductId']      = "PCPMT";
                                $data['ValidFrom']      = $res['from_date'];
                                $data['ValidUntil']     = $res['expiry_date'];
                                $data['ReferenceNo']    = $client_no;

                                $this->CommonRepository->makeCurlCallToSki('CreateSingleUseIdentifier',$data,'Reg status Active.',$reg,$res['fk_carpark_id'],$request->custID);   
                            }
                        }*/
                        /* delete reg from ski */
                        /*else
                        {
                            foreach ($carparks as $key => $res) 
                            {
                                $data['APIKey']         = $this->CommonRepository->getSkiApiKey();*/
                                /*$data['FacilityNo']   = $res['facility_no'];*/
                                /*$data['FacilityNo']     = '550012';
                                $data['ReferenceNo']    = $client_no;

                                $this->CommonRepository->makeCurlCallToSki('DeleteIdentifier',$data,'Reg status Inactive.',$reg,$res['fk_carpark_id'],$request->custID);
                            }

                            $reg_numbers = CustomerVehicalRegModel::where('fk_customer_id',$request->custID)->where('status','active')->pluck('vehicle_registration_number')->all();

                            foreach ($reg_numbers as $key => $reg) 
                            {
                                foreach ($carparks as $key => $res) 
                                {
                                    $data['APIKey']         = $this->CommonRepository->getSkiApiKey();*/
                                    /*$data['FacilityNo']   = $res['facility_no'];
                                    $data['ValidCarparks']  = [$res['ski_carpark_no']];*/
                                    /*$data['FacilityNo']     = '550012';
                                    $data['ValidCarparks']  = [0];
                                    $data['TicketNo']       = $reg;
                                    $data['TicketType']     = 4;
                                    $data['ProductId']      = "PCPMT";
                                    $data['ValidFrom']      = $res['from_date'];
                                    $data['ValidUntil']     = $res['expiry_date'];
                                    $data['ReferenceNo']    = $client_no;

                                    $this->CommonRepository->makeCurlCallToSki('CreateSingleUseIdentifier',$data,'Reg status Active.',$reg,$res['fk_carpark_id'],$request->custID);   
                                }
                            }
                        }
                    }*/
                    return response()->json($response);
                }
                else
                {
                    return response()->json($response);
                }
            }
            else
            {
                $response['message'] = "Already three registration numbers are active.";
                return response()->json($response);
            }
        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return redirect('vehicleDetails')
                ->with(['error' => Lang::get('custom.something_wrong')])
                ->withInput();
        }
    }
    public function addvehicalregNo(Request $request)
    {
        /*$collect['id'] = CustomersModel::where('id',$request->id)->value('pipedrive_id');
        
       // dd($collect['id']);
        $reg_numbers  = CustomerVehicalRegModel::where('fk_customer_id',$request->id)
                                        ->where('status','active')
                                        ->pluck('vehicle_registration_number')
                                        ->all();
        dd($reg_numbers);
        //$collect['bcf37ccb665911d769b260c36ef77c1b6109fe43'] = $reg_numbers;//test
        $collect['57b9d67acd1fb667856506008264dc6eebfb1be6'] = $reg_numbers;//live
        //dd($collect);

        $this->CommonRepository->updateRegIntoPipedrive($collect);*/
        try
        {
            $loginId = Auth::id();
            $response = $this->CommonRepository->createRegistrationNo($request);
            if ($response['status'])
            {
                /* add reg no to ski */
                /*if($response['reg_status'] == 'active' && (empty($request->effective_from) || strtotime($request->effective_from) == strtotime(date('d/m/Y'))))
                {
                    $carparks = CustomerCarparkModel::join('car_parks','car_parks.id','customer_carpark.fk_carpark_id')
                                                    ->where('fk_customer_id',$request->id)
                                                    ->where('customer_carpark.status','active')
                                                    ->whereNotNull('facility_no')
                                                    ->get(['facility_no','ski_carpark_no','from_date','expiry_date','fk_carpark_id']);
                    if(!$carparks->isEmpty())
                    {
                        $client_no = CustomersModel::where('id',$request->id)->value('client_no');
                        $reg = str_replace(" ", '', trim($request->new_reg_no));
                        foreach ($carparks as $key => $carpark) 
                        {
                            $data['APIKey'] = $this->CommonRepository->getSkiApiKey();*/
                            /*$data['FacilityNo'] = $carpark['facility_no'];
                            $data['ValidCarparks'] =[$carpark['ski_carpark_no']];*/
                            /*$data['FacilityNo'] = '550012';
                            $data['ValidCarparks'] =[0];
                            $data['TicketNo'] = $reg ;
                            $data['TicketType'] = 4;
                            $data['ProductId'] = "PCPMT";
                            $data['ValidFrom'] = $carpark['from_date'];
                            $data['ValidUntil'] = $carpark['expiry_date'];
                            $data['ReferenceNo'] = $client_no;
                            $this->CommonRepository->makeCurlCallToSki('CreateSingleUseIdentifier',$data,'Add New Reg',$reg,$carpark['fk_carpark_id'],$request->id);
                        }
                    }
                }*/
                /* add reg no to ski end */
                $client_no = CustomersModel::where('id',$request->id)->value('client_no');

                $collect['id'] = $this->CommonRepository->userExistsInPipedriveByPCPId($client_no);
                if($collect['id']>0)
                {
                    $reg_numbers  = CustomerVehicalRegModel::where('fk_customer_id',$request->id)
                                                ->where('status','active')
                                                ->pluck('vehicle_registration_number')
                                                ->all();
                    //dd($reg_numbers);
                    //$collect['bcf37ccb665911d769b260c36ef77c1b6109fe43'] = $reg_numbers;//test
                    $collect['57b9d67acd1fb667856506008264dc6eebfb1be6'] = $reg_numbers;//live
                    //dd($collect);

                    $this->CommonRepository->updateRegIntoPipedrive($collect);
                }
                return $response;
            }
            else
            {
                return $response;
            }

        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            
           // dd($exception);

            return redirect(str_plural($this->ModelPath))
                ->with(['error' => Lang::get('custom.something_wrong')])
                ->withInput();
        }
    }
    public function invoiceManagement()
    {
        
        $this->ViewData['pageTitle']      = 'Manage Invoice';
        $view = $this->ViewFolder.'invoicePDF';
     

         $pdf = PDF::loadView($view);
         return $pdf->stream('uploads');
       
    }

    public function subscriptionName(Request $request)
    {
        $rec = $selected = "";
        $rec.= '<option value="">Select Subscription</option>';
        $data  =array();
       
        $subType = $this->CommonRepository->getSubscriptiontype($request->id);
       
        // if($subType->subscription_type=='corporate')
        // {
        //     $response = $this->CommonRepository->getSubscriptionCorporateall($request->carpark_id);
        // } index()
        // else
        {
           //$response = $this->CommonRepository->getSubscriptionPersonalall($request->carpark_id);
            $response = CarparkSubscriptionModel::whereNotIn('subscription_type',['corporate'])
                                                //where('subscription_type','personal')
                                                ->where('fk_carpark_id',$request->carpark_id)
                                                ->get();
        }
       
        if (sizeof($response) > 0) 
        {
          foreach ($response as $res)
          { 
            $selected = "";
            if($request->id == $res->id)
            {
                $selected = "selected";
            }
            
            $rec.= '<option value="'.$res->id.'" '.$selected.'>'.$res->name.' ('.$res->payment_type.')</option>';
          }

          $data['rec'] = $rec;
          $data['status'] = true;
         
        }

        if($request->companyId!='')
        {
            $corporaterecord = $this->CommonRepository->getIsCorporateSubscription($request);  
           
            if (!empty($corporaterecord) > 0) 
            {
              foreach ($corporaterecord as $val)
              {

                $selected = "";
                if($request->id == $val['id'])
                {
                    $selected = "selected";
                }
                
                $rec.= '<option value="'.$val['id'].'" '.$selected.'>'.$val['name'].' ('.$val['payment_type'].')</option>';
              }

              $data['rec'] = $rec;
              $data['status'] = true;
              
            } 
        }
        return $data;
        
    }

    public function destroy(Request $request)
    {
        try
        {
            $response   = $this->BaseRepository->destroy($request);
            return response()->json($response);

        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return redirect('suppliers')
                ->with(['error' => Lang::get('custom.something_wrong')])
                ->withInput();
        }
    }

    //credit
    public function creditList($id)
    {
        $loginId = Auth::id();
        $redirectPage = '';
        $redirectPage = Session::get('redirectPage');
        Session::put('redirectPage','');
        $this->ViewData['redirectPage']     = $redirectPage;
        $this->ViewData['pageTitle']        = 'Manage Credit';
        $this->ViewData['text']['add']      = 'New Credit';
        $this->ViewData['link']['form']     = $this->ModelPath.'/addcredit/'.$id;
        $this->ViewData['form']['cancel']   = str_plural($this->ModelPath);
        $this->ViewData['id']               = $id;
        $this->ViewData['session']          = $this->CommonRepository->getsessionCount($id);
        $this->ViewData['collection']       = $this->BaseRepository->customerDetails($id);
        if (!empty($this->ViewData['collection'])) {
        return view($this->ViewFolder.'credit', $this->ViewData);
        }else {
          return redirect("customer/Customers_dashboard/".$id)
              ->with(['error' => 'Customer not found']);
        } 
    }
    public function getCredit(Request $request, $id)
    {
        $data = $request->all();
        $columns       = [];
        $page          = $data['draw'];
        $limit         = $data['length'];
        $offset        = $data['start'];
        $search        = $data['search']['value'];
        $sortBy        = $data['order'][0]['dir'];
        $sortByIndex   = $data['order'][0]['column'];
        $searchColumns = $data['columns'];

        $params['search']        = $search;
        $params['searchColumns'] = $searchColumns;

        $totalCollection = count($this->CommonRepository->getCredit($params, $id));

        if ($totalCollection)
        {
            $params['sortByIndex'] = $sortByIndex;
            $params['sortBy']      = $sortBy;
            $params['limit']       = $limit;
            $params['offset']      = $offset;

            $collections = $this->CommonRepository->getCredit($params,$id);

            foreach ($collections as $key => $collection)
            {
                $strRemovedFlag = '';
                $row = [];
                array_push($row, $offset + ($key + 1));
           
                $referName = $this->BaseRepository->GetReferName($collection['refer_to']);
                //$referredCustomer = $this->BaseRepository->GetReferName($collection['referred_customer']);

                $referred_name = '';
                if (!empty($referName->id)) 
                {
                    // $referred_name = ucfirst($referName->first_name.' '.$referName->last_name);
                    $referred_name = '<a href="'.url('/customer/Customers_dashboard/'.$referName->id).'" target="__blank">'.$referName->client_no.'</a>';
                }

                // if (!empty($referredCustomer->id)) 
                // {
                //     $referred_name = ucfirst($referredCustomer->first_name.' '.$referredCustomer->last_name);
                // }
                // array_push($row, ucfirst($referName->first_name).' '.$referName->last_name);
                array_push($row, $referred_name);
                
                /*if ($collection['transaction_type'] == 'add') 
                {*/
                    if ($collection['credit_type'] == 'session') 
                    {
                        array_push($row, '-'.round($collection['credit_value']));
                    }
                    else
                    {
                        if(!empty($collection['fee_value'] && $collection['fee_value']!='0'))
                        {
                            array_push($row, '<span style="font-size: 14px; ">£ </span> '.number_format($collection['fee_value'], 2));
                        }
                        else
                        {
                            array_push($row, '<span style="font-size: 14px; ">£ -</span> '.number_format($collection['credit_value'], 2));
                        }
                    }
                /*}
                else
                {
                    if ($collection['credit_type'] == 'session') 
                    {
                        array_push($row, round($collection['credit_value']));
                    }
                    else
                    {
                        array_push($row, number_format($collection['credit_value'], 2));
                    }
                }*/   

                array_push($row, Date('d-m-Y',strtotime($collection['transaction_date'])));
                array_push($row, $collection['transaction_type']);
                array_push($row, $collection['credit_type']);
                if($collection['vat_included'] == 'yes')
                {
                    array_push($row, ucfirst($collection['vat_included']));
                }
                else
                {
                    array_push($row, 'No');
                }
                

                array_push($row, $collection['comment']);


                if($collection['refer_status'] == 'active') 
                {

                    $activeCheckbox = '<input transaction-type="' . $collection['credit_type'] . '" type="checkbox" id="user-' . $collection['id'] . '" name="user-status-credit" checked="checked" data-size="mini"  class="user-status-credit" class="user-status" data-status="' . $collection['refer_status'] . '" data-id="' . $collection['id'] . '">';
                } 
                else 
                {
                    if($collection['refer_status'] == 'inactive')
                    {
                        $tempstatus = 'inactive';
                    }
                    else
                    {
                        $tempstatus = 'inactive';
                    }
                     $activeCheckbox = '<input transaction-type="' . $collection['credit_type'] . '"   data-size="mini" data-status="' . $tempstatus . '"  type="checkbox" id="user-' . $collection['id'] . '" name="user-status-credit" class="user-status-credit" data-id="' . $collection['id'] . '">';
                }

                // if ($collection['refer_status'] == 'active')
                // {
                //     $activeCheckbox = '<span id="activeStatus-' . $collection['id'] . '" style="color:#26dc26e0;">'.ucfirst($collection['refer_status']).'</span>'; 
                // }
                // else if ($collection['refer_status'] == 'inactive')
                // {

                //     $activeCheckbox = '<input type="checkbox" id="user-' . $collection['id'] . '" name="user-status-credit" class="user-status-credit" data-id="' . $collection['id'] . '">';
                    
                // }
                // else
                // {
                //     $activeCheckbox = '<span style="color:red;">'.ucfirst($collection['refer_status']).'</span>';
                // }
               
                //if($collection['credit_value'] == $collection['base_credit'] )
                {
                    $activeRemove = '<a data-status="' . $collection['refer_status'] . '" transaction-type="' . $collection['credit_type'] . '" id="remove-' . $collection['id'] . '" title="Removed" data-id="' . $collection['id'] . '" class="btn btn-danger" onclick="changeStatusRemove(this)" data-status="remove">Remove</a>';  
                }
                // else
                // {
                //     $activeRemove = '';
                // }
                array_push($row, $activeCheckbox);
                array_push($row, $activeRemove);
                $columns[] = $row;
            }
        }

        $response = [
            'status'          => true,
            'draw'            => $page,
            'data'            => $columns,
            'recordsTotal'    => $totalCollection,
            'recordsFiltered' => $totalCollection
        ];

        return Response::json($response);
    }

    public function updateCreditStatus(Request $request)
    {
        try
        {
            $getRec = $this->BaseRepository->getCreditStatus($request->id);
            if($getRec)
            {
                $response = $this->BaseRepository->updateCreditStatus($getRec->id,$getRec->refer_status,$request);
                if ($response['status'])
                {
                    return response()->json($response);
                }
                else
                {
                    return response()->json($response);
                }
            }
        } 
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return redirect('supplier')
                ->with(['error' => Lang::get('custom.something_wrong')])
                ->withInput();
        }
    }


     public function removeCreditStatus(Request $request)
    {
        
        try
        {
            $getRec = $this->BaseRepository->getCreditStatus($request->id);
            //dd($getRec);
            if($getRec)
            {
                $response = $this->BaseRepository->removeCreditStatus($getRec->id,$getRec->refer_status,$request);
                if ($response['status'])
                {
                    return response()->json($response);
                }
                else
                {
                    return response()->json($response);
                }
            }
        } 
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return redirect('supplier')
                ->with(['error' => Lang::get('custom.something_wrong')])
                ->withInput();
        }
    }

    public function addCredit($id)
    {
        if(!empty($id))
        {  
            $this->ViewData['customers']    = $this->CommonRepository->getActiveCustomersAll();
            $this->ViewData['pageTitle']    = 'Add Credit';
            $this->ViewData['id']           = $id;
            $this->ViewData['link']['form'] = url('customer/addcredit/'.$id);
            
            return view($this->ViewFolder.'addcredit', $this->ViewData);
        }
        else
        {
            abort(404);
        }
    }

    public function storeCredit(Request $request,$id)
    {
        $validator = Validator::make($request->all(),
        [
            'is_refrral_credit'=>
            [
                'required',
            ],
            'comment'         => 'required',
            'transaction_type'=>
            [
                'required',
            ]
        ]);

        if ($validator->fails())
        {
            return back()
                ->withErrors($validator)  
                ->withInput();
        }

        // check if session credit then it should not greater than 10
        try
        {
            //dd($request->all());
            $response = $this->BaseRepository->createCredit($request,$id);
            if ($response['status'])
            {

                return redirect('customer/credit/'.$id)
                    ->with(['success' => $response['message']]);
            }
            else
            {
                return redirect('customer/credit/'.$id)
                    ->with(['error' => $response['message']])
                    ->withInput();
            }
        }  
        catch (\Exception $exception)
        {
            //dd($exception);
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return redirect(str_plural($this->ModelPath))
                ->with(['error' => Lang::get('custom.something_wrong')])
                ->withInput();
        }
    }

    public function checkCreditValue(Request $request)
    {
        $creditValue = $this->BaseRepository->getCreditValue($request);
        if(!empty($creditValue))
        {
            if(!empty($creditValue->credit) && $request->type == 'credit')
            {
                return $creditValue->credit;    
            }
            else if(!empty($creditValue->session) && $request->type == 'session')
            {
                return $creditValue->session;
            }
            else
            {
                return 0;
            }
            
        }
    }

   

    public function getcarpark()
    {
        $response = $this->CommonRepository->getCarparkAll();
        $rec = '';
        $rec= '<option value="">Select Carpark</option>';
        foreach ($response as $obj)
        {

            $rec.= '<option value="'.$obj['id'].'">'.$obj['name'].'</option>';

        }
        return $rec;
    }

    public function availableCarpark(Request $request,$id)
    {
        $arr = $request->all();
        $response = $this->CommonRepository->availableCarpark($request->all(),$id);

        $recCarpark = '';

        $cnt = 0;
        foreach ($response->CSubscription as $obj)
        {
            if (in_array($obj['carpark']['id'], $arr['arr']))
            {

            }
            else
            {
                if($cnt == 0)
                {
                    $recCarpark .= '<option value="">Select Carpark</option>';
                    $cnt++;
                }
                $recCarpark.= '<option value="'.$obj['carpark']['id'].'" >'.$obj['carpark']['name'].'</option>';
            }
            //$cnt++;
        }
        return $recCarpark;


    }
    public function getSubscription(Request $request)
    {
        $rec = "";
        $rec.= '<option value="">Select Subscription</option>';
        $data  =array();

        
        
        if($request->corporatename!='' && $request->corporatename!='-')
        {
            $response = $this->CommonRepository->getSubscriptionCorporate($request->carpark_id);
        }
        else
        {
           $response = $this->CommonRepository->getSubscriptionPersonal($request->carpark_id);
        }
        //dd($response);
        $subscription_rate=\App\Models\ProspectiveCustomerCarparkModel::where('fk_carpark_id',$request->carpark_id)->where('fk_customer_id',$request->prospective_customer_id)->value('amount');
        
        if (sizeof($response) > 0) 
        {
          $initial_diff = abs($response[0]->rate-$subscription_rate);
          $closest = $response[0]->rate;
          
          foreach ($response as $res)
          {
            
            if($res->rate == $subscription_rate)
            {
                //echo "<br>yes".$res->rate;
                $closest = $res->rate;
                break;
            }
            else
            {
                /* get the closest rate */
                //echo "<br>no".$res->rate;
                $new_diff = abs($res->rate - $subscription_rate);
                
                if($new_diff < $initial_diff)
                {
                    $initial_diff = $new_diff;
                    $closest = $res->rate;
                }
            }
            
          }

          foreach ($response as $res)
          {
            $rec.='<option value="'.$res->id.'"';

            if($request->is_prospective_customer == 'yes')
            {
                if($res->rate == $closest)
                {
                    $rec.=' selected';
                }
            }

            $rec.='>'.$res->name.' ('.$res->payment_type.')</option>';
          }

          $data['rec'] = $rec;
          $data['status'] = true;
          
        }
       
        if($request->companyId!='')
        {
            $corporaterecord = $this->CommonRepository->getIsCorporateSubscription($request);   

            if (!empty($corporaterecord) > 0) 
            {
              foreach ($corporaterecord as $val)
              {
                $rec.= '<option value="'.$val['id'].'" >'.$val['name'].' ('.$val['payment_type'].')</option>';
              }

              $data['rec'] = $rec;
              $data['status'] = true;
              
            } 
        }
       
       
        return $data;
    }

    public function Customers_dashboard($id='')
    {
        $settings   = SettingsModel::first(); 
        $defaultVat = $settings->vat;
        $this->ViewData['objcustomer']      = $this->BaseRepository->getCustomers($id);
        if (!empty($this->ViewData['objcustomer'])) {
        Session::put('customerDashboard',''); 
        $this->ViewData['pageTitle']    = 'Dashboard';
        $this->ViewData['object']       = $this->BaseRepository->getCollection($id);

        $this->ViewData['invoices']     = $this->BaseRepository->getInvoices($id);
       
        $this->ViewData['registration'] = $this->BaseRepository->getRegistration($id);
        
        $this->ViewData['comment']      = $this->BaseRepository->getComment($id);
        $this->ViewData['credit']       = $this->BaseRepository->getcredit($id);
        
        $this->ViewData['session']      = $this->BaseRepository->getSession($id);
        $this->ViewData['historical_overlays']   = $this->BaseRepository->historical_overlays($id);
       
        $this->ViewData['collection']   = $this->BaseRepository->getActiveproduct($id);
        $this->ViewData['defaultVat']   = $defaultVat;
       
        $this->ViewData['carparkId']    = $this->BaseRepository->getcarparkClientid($id);
        
        $this->ViewData['ClientIdSub']  = $this->BaseRepository->geClientIdSub($id);
        $this->ViewData['id']           = $id;
        $this->ViewData['join_date']    = $this->BaseRepository->getJoinDate($id);
        $this->ViewData['allDate']      = $this->BaseRepository->getAllDate($id);
        
        return view($this->ViewFolder.'dashboard', $this->ViewData);
        }else {
          return redirect("customers")
              ->with(['error' => 'Customer not found']);
        } 
    }

    public function getProduct(Request $request) 
    {
        $response     = $this->CommonRepository->getCarparkDetails($request->id);
        $Subscription = $this->CommonRepository->getSubscriptionDetails($request->subscriptionId);
        $arr['carparkName']     =   $response->name;             
        $arr['subscriptionName'] =  $Subscription->name;
        return response()->json($arr);
    }

    public function customerNewReferral($id)
    {
        if(!empty($id))
        {
            $this->ViewData['collection'] = $this->BaseRepository->customerNewReferral($id);
            $this->ViewData['customers']  = $this->CommonRepository->getActiveCustomersAll();
            $this->ViewData['pageTitle']  = 'Add Referral';
            $this->ViewData['link']['form'] = url('customers/new/referral/'.$id.'/create');
            $this->ViewData['link']['referred_by'] = url('customers/getReferredLists');
            return view($this->ViewFolder.'newreferral', $this->ViewData);
        }
        else
        {
            abort(404);
        }
    }

    public function createCustomerNewReferral(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'referred_by'   => "required",
            'credit_value'  => 'required_unless:credit_type,'
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator);
        }

        if ($request->credit_type == 'session') 
        {
            $validator = Validator::make($request->all(), [
                'credit_value'  => 'digits_between:1,10'
            ]);

            if ($validator->fails()) {
                return redirect()->back()->withErrors($validator);
            }
        }
    
        $response = $this->BaseRepository->createCustomerNewReferral($request);
        if ($response['status'])
        {
            return redirect($response['url'])
                ->with(['success' => $response['message']]);
        }
        else
        {
            return back()
                ->with(['error' => $response['message']])
                ->withInput();
        }
        
    }

    public function getReferredCustomerLists(Request $request)
    {   
        $jsondata = [];

        if(!empty($request->id))
        {
            $customerCredits  = $this->CommonRepository->getReferredCustomerLists($request->id);
            if(!empty($customerCredits) && sizeof($customerCredits) > 0)
            {
                $jsondata['status'] = 1;
                $jsondata['data'] = '';
                foreach($customerCredits as $key => $credit)
                {
                    
                    $jsondata['data'] .= '<tr role="row">
                                            <td class="">'.$credit->referred->client_no.'</td>
                                            <td class="">'.(ucfirst($credit->referred->first_name.' '.$credit->referred->last_name)).'</td>
                                        </tr>';  
                }    
            }
            else
            {
                $jsondata['status'] = 0;
                $jsondata['msg'] = 'Not found referral Customers.';
            }
        }
        else
        {
            $jsondata['status'] = 0;
            $jsondata['msg'] = 'Referrer Customer not found.';
        }

        return response()->json($jsondata);
    }
    /*
    Customer Subscription List
    */
    public function customerSubscription()
    {
     
        $corporates = $this->CorporateRepository->getRecords();
        $this->ViewData['pageTitle']    = 'Manage Customer Subscription';
        $this->ViewData['corporates']   = $corporates;
        $this->ViewData['carparks']     = $this->BaseRepository->getCarparkRecords();
        $this->ViewData['subscription'] = $this->BaseRepository->getSubscriptionRecords();

        return view($this->ViewFolder.'customerSubscription', $this->ViewData);
    }    
    public function getCustomerSubscriptionRecords(Request $request)
    {
        $data = $request->all();
        $columns       = [];
        $page          = $data['draw'];
        $limit         = $data['length'];
        $offset        = $data['start'];
        $search        = $data['search']['value'];
        $sortBy        = $data['order'][0]['dir'];
        $sortByIndex   = $data['order'][0]['column'];
        $searchColumns = $data['columns'];

        $params['search']        = $search;
        $params['searchColumns'] = $searchColumns;

        $totalCollection = count($this->BaseRepository->getCustomerSubscriptionRecords($params));

        if ($totalCollection)
        {
            $params['sortByIndex'] = $sortByIndex;
            $params['sortBy']      = $sortBy;
            $params['limit']       = $limit;
            $params['offset']      = $offset;

            $collections = $this->BaseRepository->getCustomerSubscriptionRecords($params); 
            foreach ($collections as $key => $collection)
            {
                if(!in_array($collection['sub_id'], $collections->toArray()))
                {
                $getClientid = $this->BaseRepository->getClientId($collection['cust_id'],$collection['fk_carpark_id']);
                $clientId = '';
              
                $cnt=0;
                if(!empty($getClientid) && sizeof($getClientid)>0)
                {
                    foreach ($getClientid as $val)
                    {

                        if($cnt==0)
                        {
                            $clientId .= $val['client_id'];
                        }
                        else
                        {
                            $clientId .= ','.$val['client_id'];
                        }
                        $cnt++; 
                    } 
                } 
                else
                {
                  $clientId = '';   
                }
                    
                $row = [];
                $action = "
                    <a href='" . url('customer/Customers_dashboard/' . $collection['cust_id']) . "' class='comment-user action-icon' data-id='" . $collection["cust_id"] . "' title='Dashboard'><span class='fa fa-dashboard'></span></a>
                    ";
                  
                array_push($row, $action);
                // array_push($row, $offset + ($key + 1));

                array_push($row, ucfirst($collection['first_name'].' '. $collection['last_name']));
                if($collection['corporate_name'] !='')
                {
                    array_push($row, $collection['corporate_name']);
                }
                else
                {
                    array_push($row,$collection['companyName']);
                }

                array_push($row, $collection['email']);

                array_push($row, $collection['client_no']);

                array_push($row, ucfirst($collection['carpark_name']));
                array_push($row, ucfirst($collection['subscription_name']));
                array_push($row, $clientId); 
                //
                array_push($row, ucfirst($collection['rate'])); 
                array_push($row, ucfirst($collection['customer_status']));

                /*if($collection['customer_type'] == '1' && !empty($collection['fk_corporate_id']))
                {
                    if($collection['customer_status'] == 'inactive')
                    {
                        array_push($row, '3');//both
                    }
                    else
                    {
                       array_push($row, '0');//both 
                    }
                    
                }
                else if(!empty($collection['fk_corporate_id']) && empty($collection['customer_type']))
                {
                    if($collection['customer_status'] == 'inactive')
                    {
                        array_push($row, '3');//both
                    }
                    else
                    {
                        array_push($row,"2");//indi
                    }
                    
                }
                else if(empty($collection['fk_corporate_id']) && empty($collection['customer_type']))
                {
                    if($collection['customer_status'] == 'inactive')
                    {
                        array_push($row, '3');//both
                    }
                    else
                    {
                        array_push($row,"1"); //corporate
                    }
                    
                }*/
                $customer_active_subs = CustomerCarparkModel::leftJoin('carpark_subscription','carpark_subscription.id','customer_carpark.fk_carpark_subscription')->where('fk_customer_id',$collection['cust_id'])->where('customer_carpark.status','active')->pluck('subscription_type')->all();
                if($collection['status'] == 'inactive')
                {
                    array_push($row,'3');
                }
                else
                {
                    if(in_array('personal', $customer_active_subs) &&in_array('corporate', $customer_active_subs))
                    {
                        array_push($row,'0');       
                    }
                    else if(in_array('personal', $customer_active_subs))
                    {
                        array_push($row,'1');          
                    }
                    else if(in_array('corporate', $customer_active_subs))
                    {
                        array_push($row,'2'); 
                    }
                }

                $columns[] = $row;
                }
            }
        }

        $response = [
            'status'          => true,
            'draw'            => $page,
            'data'            => $columns,
            'recordsTotal'    => $totalCollection,
            'recordsFiltered' => $totalCollection
        ];

        return Response::json($response);
    }

    public function createContactOnXero($id)
    {   
        $xeroInvoice = [];   

        $contactId = $this->CommonRepository->createContact($id);         
        
    }

    public function xeroCallback()
    { 
        $collection = $this->CommonRepository->xeroCallback($_GET);
    }

    public function carparkClientId($id)
    {   
        $rec = $this->BaseRepository->getCustomers($id);
        if(!empty($rec)>0)
        {
            $this->ViewData['pageTitle']  = 'Add Client Id';
            $this->ViewData['id']         = $id;
            $this->ViewData['collection'] = $this->BaseRepository->getCustomerCaraprkRecord($id);

            return view($this->ViewFolder.'carparkClientId', $this->ViewData);
        }
        else
        {
            return redirect(str_plural('customers'))
                    ->with(['error' => 'Customer not exist.'])
                    ->withInput();
        }    
    }

    public function createCarparkClientId(Request $request)
    {
        try
        {
            $response = $this->BaseRepository->createClientId($request);
            if ($response['status'])
            {
                if($request->type == 'view')
                {
                    return redirect("customer/carparkClientEdit/".$request->fk_customer_id)
                    ->with(['success' => $response['message']])
                    ->withInput();
                }  
                if($request->type == 'list')
                {
                    return redirect(str_plural($this->ModelPath))
                   ->with(['success' => $response['message']]);
                }
                 if($request->type == 'edit')
                {
                    return redirect("customer/edit/".$request->fk_customer_id)
                   ->with(['success' => $response['message']]);
                }
                if($request->type == 'dashboard')
                {
                    return redirect('customer/Customers_dashboard/'.$request->fk_customer_id)
                   ->with(['success' => $response['message']]);
                }
                
            }
            else
            {
                if($request->type == 'view')
                {
                    return redirect("customer/carparkClientEdit/".$request->fk_customer_id)
                    ->with(['error' => $response['message']])
                    ->withInput();
                }  
                if($request->type == 'list')
                {
                    return redirect(str_plural($this->ModelPath))
                    ->with(['error' => $response['message']])
                    ->withInput();
                }
                 if($request->type == 'edit')
                {
                    return redirect("customer/edit/".$request->fk_customer_id)
                   ->with(['error' => $response['message']])
                    ->withInput();
                }
                if($request->type == 'dashboard')
                {
                    return redirect('customer/Customers_dashboard/'.$request->fk_customer_id)
                   ->with(['error' => $response['message']])
                    ->withInput();
                }
            }
 
        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());

                if($request->type == 'view')
                {
                    return redirect("customer/carparkClientEdit/".$request->fk_customer_id)
                    ->with(['error' => Lang::get('custom.something_wrong')])
                    ->withInput();
                }  
                if($request->type == 'list')
                {
                    return redirect(str_plural($this->ModelPath))
                    ->with(['error' => Lang::get('custom.something_wrong')])
                    ->withInput();
                }
                 if($request->type == 'edit')
                {
                    return redirect("customer/edit/".$request->fk_customer_id)
                    ->with(['error' => Lang::get('custom.something_wrong')])
                    ->withInput();
                }
                if($request->type == 'dashboard')
                {
                    return redirect('customer/Customers_dashboard/'.$request->fk_customer_id)
                    ->with(['error' => Lang::get('custom.something_wrong')])
                    ->withInput();
                }
        }
    }

    public function carparkClientIdEdit($id)
    {  
        $rec = $this->BaseRepository->getCustomers($id);
        if(!empty($rec)>0)
        {
            $this->ViewData['pageTitle']  = 'Edit Client Id';
            $this->ViewData['id']         = $id;
            $this->ViewData['type']       = 'dashboard';
            $this->ViewData['customers']  = $rec; 
            $this->ViewData['carparkId']  = $this->BaseRepository->getcarparkClientid($id);
            $this->ViewData['collection'] = $this->BaseRepository->getCarparkClientIdRecord($id);
            return view($this->ViewFolder.'carparkClientIdEdit', $this->ViewData);
        }
        else
        {
            return redirect(str_plural('customers'))
                    ->with(['error' => 'Customer not exist.'])
                    ->withInput();
        }  
    }

    public function CustomerEditClientId($id)
    {  
        $rec = $this->BaseRepository->getCustomers($id);
        if(!empty($rec)>0)
        {
            $this->ViewData['pageTitle']  = 'Edit Client Id';
            $this->ViewData['id']         = $id;
            $this->ViewData['type']       = 'edit';
            $this->ViewData['customers']  = $rec; 
            $this->ViewData['collection'] = $this->BaseRepository->getCarparkClientRecord($carparkId,$id);

            return view($this->ViewFolder.'carparkClientIdEdit', $this->ViewData);
        }
        else
        {
            return redirect(str_plural('customers'))
                    ->with(['error' => 'Customer not exist.'])
                    ->withInput();
        }  
    }

    public function removeCaraprkClientId(Request $request)
    {
        try
        {
            $response = $this->BaseRepository->removeCaraprkClientId($request);
            if ($response['status'])
            {
                return 1;
            }
            else
            {
                return 0;
            }
 
        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return redirect(str_plural($this->ModelPath))
                ->with(['error' => Lang::get('custom.something_wrong')])
                ->withInput();
        }
    }

    public function clientIdUpdate(Request $request, $id)
    {
        // try
        // {
              $response = $this->BaseRepository->clientIdUpdate($request,$id);
           
            if ($response['status'])
            {
                if($request->type == 'dashboard')
                {
                    return redirect('customer/Customers_dashboard/'.$id)
                   ->with(['success' => $response['message']]);
                }
                if($request->type == 'edit')
                {
                    return redirect("customer/edit/".$id)
                    ->with(['success' => $response['message']])
                    ->withInput();
                } 
                if($request->type == 'view')
                {
                    return redirect("customer/carparkClientEdit/".$id)
                    ->with(['success' => $response['message']])
                    ->withInput();
                }  
               
            }
            else
            {
                if($request->type == 'dashboard')
                {
                    return redirect('customer/Customers_dashboard/'.$id)
                    ->with(['error' => $response['message']])
                    ->withInput();
                }
                if($request->type == 'edit')
                {
                    return redirect("customer/edit/".$id)
                    ->with(['error' => $response['message']])
                    ->withInput();
                } 
                 if($request->type == 'view')
                {
                    return redirect("customer/carparkClientEdit/".$id)
                    ->with(['success' => $response['message']])
                    ->withInput();
                }    
            }
 
        // }
        // catch (\Exception $exception)
        // {
        //     Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
        //     if($request->type == 'dashboard')
        //     {
        //         return redirect('customer/Customers_dashboard/'.$id)
        //         ->with(['error' => Lang::get('custom.something_wrong')])
        //         ->withInput();
        //     }
        //     if($request->type == 'edit')
        //     {
        //         return redirect("customer/edit/".$id)
        //         ->with(['error' => Lang::get('custom.something_wrong')])
        //         ->withInput();
        //     } 
        //     if($request->type == 'view')
        //     {
        //         return redirect("customer/carparkClientEdit/".$id)
        //         ->with(['error' => Lang::get('custom.something_wrong')])
        //         ->withInput();
        //     }       
        // }
    }

    public function getcarparkWiseClientId(Request $request)
    {
       //dd("-->");
        $collection = $this->BaseRepository->getCarparkClientRecord($request->carpark_id,$request->id,$request->sub_id);
        return $collection;
      
        
    }

    public function getCarparkName(Request $request)
    {
        $collection = $this->BaseRepository->getcarprakname($request->carpark_id);
        return $collection;
    }

    public function changeSubscriptionStatus(Request $request)
    {
        $response = $this->BaseRepository->changeSubscriptionStatus($request);

        /* delete from ski for the carpark */
        /*$details = CarParksModel::select('facility_no','ski_carpark_no')
                                ->where('id',$request->carpark_id)
                                ->whereNotNull('facility_no')
                                ->first();

        if(!empty($details))
        {
            $client_no = CustomersModel::where('id',$request->customer_id)->value('client_no');
            
            /*$data['APIKey'] = $this->CommonRepository->getSkiApiKey();
            /*$data['FacilityNo'] = $details->facility_no;*/
            /*$data['FacilityNo'] = '550012';
            $data['ReferenceNo'] = $client_no;
            
            $this->CommonRepository->makeCurlCallToSki('DeleteIdentifier',$data,'Inactivate/Removeed Subscription','',0,$request->customer_id);

            /* add identifier for other carparks for same facility */
            
            /*$reg_numbers = CustomerVehicalRegModel::where('fk_customer_id',$request->customer_id)
                                                    ->where('status','active')
                                                    ->pluck('vehicle_registration_number')
                                                    ->all();

            $carpark_details = CarParksModel::join('customer_carpark','customer_carpark.fk_carpark_id','car_parks.id')
                    ->where('facility_no',$details->facility_no)
                    ->where('fk_customer_id',$request->customer_id)
                    ->where('customer_carpark.status','active')
                    ->where('car_parks.id','!=',$request->carpark_id)
                    ->get(['car_parks.id','from_date','expiry_date','ski_carpark_no']);
            
            if(!$carpark_details->isEmpty())
            {
                foreach ($reg_numbers as $key => $reg) 
                {
                    foreach ($carpark_details as $key => $details) 
                    {
                        $startDate  = str_replace('/', '-', $details['from_date']);
                        $from = date('Y-m-d',strtotime($startDate));

                        if(!empty($details['expiry_date']))
                        {
                            $endDate  = str_replace('/', '-', $details['expiry_date']);
                            $expiry_date = date('Y-m-d',strtotime($endDate));
                        }
                        else
                        {
                            $expiry_date = null;
                        }

                        $data['APIKey'] = $this->CommonRepository->getSkiApiKey();*/
                        /*$data['FacilityNo'] = $details['facility_no'];
                        $data['ValidCarparks'] =[$details['ski_carpark_no']];*/
                        /*$data['FacilityNo'] = '550012';
                        $data['ValidCarparks'] =[0];
                        $data['TicketNo'] =  $reg;
                        $data['TicketType'] = 4;
                        $data['ProductId'] = "PCPMT";
                        $data['ValidFrom'] = $from;
                        $data['ValidUntil'] = $expiry_date;
                        $data['ReferenceNo'] = $client_no;
                        
                        $this->CommonRepository->makeCurlCallToSki('CreateSingleUseIdentifier',$data,'Add for other carparks than deleted for facility',$reg,$details['id'],$request->customer_id);   
                    }
                }
            }
        }*/

        return $response;
    }

    public function customers_activity_log($id)
    {
        
        //$getrecord = $this->BaseRepository->getHistoryrecord($id);
        
        /*if(!empty($getrecord))
        {*/
        
            $this->ViewData['pageTitle']        = 'Activity Log';
            $this->ViewData['collection']       = $this->BaseRepository->getHistoryCustomerDetails($id,'Subscription_wise');
            $this->ViewData['type']  = 'Subscription_wise'; 
            $this->ViewData['customerHistoryId']= $id;  
            $this->ViewData['carpark_id']       = $this->BaseRepository->getcarparkId($id); 
            session::put('History_customer_id','');
            
            return view($this->ViewFolder.'activityLog', $this->ViewData);
       /* }
        else
        {
            $cust_id = session::get('History_customer_id');
            return redirect('customers');
                  
        }*/
        
    }

    public function getactivityLogRecord(Request $request,$id)
    {
        $arr = [];
        $arr['subscriptionID']   = $request->subscriptionID;
        $arr['corporateID']      = $request->corporateID;
        $arr['carparkId']        = $request->carparkId;
        $arr['hdcorporateSubId'] = $request->hdcorporateSubId;
        $arr['type']             = $request->type;

        $data = $request->all();

        $columns       = [];
        $page          = $data['draw'];
        $limit         = $data['length'];
        $offset        = $data['start'];
        $search        = $data['search']['value'];
        $sortBy        = $data['order'][0]['dir'];
        $sortByIndex   = $data['order'][0]['column'];
        $searchColumns = $data['columns'];

        $params['search']         = $search;
        $params['searchColumns']  = $searchColumns;
        $params['corporateSubId'] = $request->hdcorporateSubId;
        $params['type']           = $request->type;
        $params['carpark_id']     = $request->carpark_id; 
        $params['customer_id']    = $request->customer_id;  
        
        $totalCollection = count($this->BaseRepository->getActivityLog($params,$id));
        if ($totalCollection)
        {
            $params['sortByIndex'] = $sortByIndex;
            $params['sortBy']      = $sortBy;
            $params['limit']       = $limit;
            $params['offset']      = $offset;

            $collections = $this->BaseRepository->getActivityLog($params,$id); 
            
            foreach ($collections as $key => $collection)
            {   
                $row = [];
                array_push($row, $offset + ($key + 1));
                array_push($row, $collection['carparkName']);
                array_push($row, $collection['subName']);
                array_push($row, ucfirst($collection['is_special_rate_applied']));
                array_push($row, $collection['rate']);
                
                $wrongDate = '1990-01-01';
                $fromDaste =  Date('Y-m-d',strtotime($collection['from_date']));
                if($collection['from_date']!='' && strtotime($fromDaste)!= strtotime($wrongDate))
                {
                    array_push($row, Date('d/m/Y',strtotime($collection['from_date'])));
                }
                else 
                {
                    array_push($row, 'N/A');
                }
                if($collection['expiry_date']!='' && strtotime($fromDaste)!= strtotime($wrongDate))
                {
                    array_push($row, Date('d/m/Y',strtotime($collection['expiry_date'])));  
                   
                }
                else 
                {
                    array_push($row, 'N/A');
                  
                }
                if($collection['status'] == 'up_coming')
                {
                    array_push($row, 'Up Coming');
                }
                else
                {
                    array_push($row, ucfirst($collection['status']));
                }
                array_push($row, ucfirst($collection['comment']));

                $role = $this->BaseRepository->getlogingDetail(Auth::id());
                // if($role == 1)
                // {
                     $action = '<a onclick="customerHistoryDelete('.$collection["id"].')" class="delete-user action-icon"  title="Delete"><span class="glyphicon glyphicon-trash"></span></a>

                           <a href="' . url('customer/customerHistoryEdit/' . $collection['id'].'/'.$request->type) . '" class="delete-user action-icon" data-type="'.$request->type.'"  title="Edit"><span class="glyphicon glyphicon-edit"></span></a>';
                // }
                // else
                // {
                //     $action = '';
                // }
               


                array_push($row, $action);


                $columns[] = $row;
            }
        }

        $response = [
            'status' => true,
            'draw' => $page,
            'data' => $columns,
            'recordsTotal' => $totalCollection,
            'recordsFiltered' => $totalCollection
        ];

        return Response::json($response);
    }

    public function carparkWiseActivity(Request $request,$id)
    {
        $this->ViewData['pageTitle']        = 'Activity Log'; 
        $this->ViewData['collection']       = $this->BaseRepository->getHistoryCustomerDetails($id,'carpark_wise'); 
        $this->ViewData['customerHistoryId']= $id;
        $this->ViewData['type']             = 'carpark_wise'; 
        $this->ViewData['carpark_id']       = $request->actitvity_caraprk; 
        return view($this->ViewFolder.'activityLog', $this->ViewData);
    }

    function checkCompanyIsCorporate(Request $request)
    {       
        try
        {
            $response = $this->BaseRepository->checkCompanyIsCorporate($request);
            return $response;
        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return redirect(str_plural($this->ModelPath))
                ->with(['error' => Lang::get('custom.something_wrong')])
                ->withInput();
        }
    }

    function expiredInactivationDate(Request $request,$type)
    {
        $response = $this->BaseRepository->expiredInactivationDate($request,$type);
        return $response;
    }

    function checkDublicatRegistrationNo(Request $request)
    {
        
        $response = $this->BaseRepository->checkDublicatRegistrationNo($request);   
        return $response;
    }
    function getlastInvoiceDate(Request $request)
    {
        $response = $this->BaseRepository->getlastInvoiceDate($request);   
        return $response;
    }

    function effectiveDate(Request $request)
    {

        $response = $this->BaseRepository->effectiveDate($request);   
        return $response;
    }

    function generateClientId(Request $request)
    {
        $response = $this->BaseRepository->generateClientId($request);   
        return $response;
    }

    function prevoiousDate(Request $request)
    {
        $response = $this->BaseRepository->prevoiousDate($request);  
       
        return $response;
    }

    function changeStanderRate(Request $request)
    {
        $response = $this->BaseRepository->changeStanderRate($request);  
        return $response;
    }

    function customerHistoryDelete(Request $request)
    {
        $response = $this->BaseRepository->customerHistoryDelete($request);
        if($response!=1)
        {
            session::put('History_customer_id',$response);
            return $response;

        }
        else
        {
            return $response;
        }
        
       
    }

    function customerHistoryEdit($id,$type)
    {
        
        $this->ViewData['pageTitle']   = 'Edit History'.$this->ModelTitle;
        $this->ViewData['collection']  = $this->BaseRepository->getcustomerhistory($id);
       
        $this->ViewData['id']               = $id;
        $this->ViewData['type']             = $type; 
    
        return view($this->ViewFolder.'edit_history', $this->ViewData);

    }

    function updatecustomerHistory(Request $request,$id)
    {
        try
        {
            $response = $this->BaseRepository->updatecustomerHistory($request,$id);
            if ($response== 'true')
            {
                return redirect('customer/customerHistoryEdit/'.$id.'/'.$request->hd_type)
                    ->with(['success' => 'Customer history updated successfully.']);
            }
            else
            {
                return redirect('customer/customerHistoryEdit/'.$id.'/'.$request->hd_type)
                    ->with(['error' => 'Customer history not
                     upldated successfully.'])
                    ->withInput();
            }

        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return redirect('customer/customerHistoryEdit/'.$id.'/'.$request->hd_type)
                ->with(['error' => Lang::get('custom.something_wrong')])
                ->withInput();
        } 
    }

    function getLinkAccountView(Request $request)
    {
        $id=trim($request->id);
        
        $this->ViewData['id']=$id;
        
        $this->ViewData['customer']=CustomersModel::where('id',$id)
                                                  ->first(['first_name','last_name','client_no']);

        $this->ViewData['customers_to_link']=CustomersModel::where('is_account_linked',false)
        ->where('id','!=',$id)
            ->select('client_no','first_name','last_name','id')
            ->get();

        $this->ViewData['master_id']=$this->getMasterId($id);

        $this->ViewData['linked_clients']=$this->getLinkedAccounts($id);
        
        $this->ViewData['pageTitle']   = 'Link Customer Account';
        $this->ViewData['link'] = url('customer/link-account');
        
        return view($this->ViewFolder.'link-account',$this->ViewData);
    }

    function getMasterId($id)
    {
        $master_id=ClientAccountLinkModel::where('child_customer_id',$id)
                                        ->value('master_customer_id');
        return $master_id;
    }
    function getLinkedAccounts($id)
    {
        
        $master_id=$this->getMasterId($id);

        if(!empty($master_id))
        {
            $clients= ClientAccountLinkModel::where('master_customer_id',$master_id)
            ->leftJoin('customers','customers.client_no','client_account_link.child_pcp_client_id')
            ->get(['first_name','last_name','master_customer_id','master_pcp_client_id','child_customer_id','child_pcp_client_id'])
            ->toArray();

            return $clients;
        }
        else
        {
            return array();
        }
    }

    function linkAccount(Request $request)
    {
        //dd($request->all());
        $client_account_link_model =ClientAccountLinkModel::
            where('master_customer_id',$request->clients[0]['master_customer_id'])
            ->delete();
        
        if(count($request->clients)>1)
        {
            $client_account_link_model = new ClientAccountLinkModel;
            
            if(!empty($request->clients))
                $client_account_link_model->insert($request->clients);
            
            if(!empty($request->ids))
                CustomersModel::whereIn('client_no',$request->ids)
                            ->update([
                                'is_account_linked'=>true
                            ]);
        }

        if(!empty($request->removedIds))
        {
            
            CustomersModel::whereIn('client_no',$request->removedIds)
                            ->update([
                                'is_account_linked'=>false
                            ]);
        }
        
        return "true";
    }

    public function viewRenewSubscription($id)
    {
        $this->ViewData['pageTitle']   = 'Renew Subscription';
        $subscription = CustomerCarparkModel::join('car_parks','car_parks.id','customer_carpark.fk_carpark_id')
                                            ->join('carpark_subscription','carpark_subscription.id','customer_carpark.fk_carpark_subscription')
                                            ->where('customer_carpark.id',$id)
                                            ->get(['car_parks.name as carpark','customer_carpark.id','customer_carpark.fk_carpark_id','duration','duration_type','fk_carpark_subscription','is_special_rate_applied','customer_carpark.rate','vat_included','payment_type','to_date','expiry_date','fk_customer_id','customer_carpark.commission']);
        $this->ViewData['details'] = $subscription[0];
        if(empty($subscription[0]['commission']) || $subscription[0]['commission'] == '0')
        {
            $this->ViewData['details']['commission'] = CarparkSubscriptionModel::where('id',$subscription[0]['fk_carpark_subscription'])->value('commission');
        }
        $this->ViewData['id'] = $id;
        $nextDate   = $this->getNextDate($subscription[0]);
        $this->ViewData['nextDate']             = $nextDate; 
        $this->ViewData['subscriptions']=CarparkSubscriptionModel::where('fk_carpark_id',$subscription[0]->fk_carpark_id)->where('payment_type','ST')->wherenull('deleted_at')->where('status','active')->where('subscription_type','personal')->pluck('id','name')->all();

        $company = CustomersModel::where('id',$subscription[0]->fk_customer_id)->value('fk_company_id');
        //dd($subscription[0]->);
        if(!empty($company))
        {
            $is_corporate = CorporatesModel::where('fk_company_id',$company)->exists();
            //dd($is_corporate);
            if($is_corporate)
            {
                
                $request=['carpark_id'=>$subscription[0]->fk_carpark_id,'custid'=>$subscription[0]->fk_customer_id,'companyId'=>$company];

                $this->ViewData['corporaterecord'] = $this->getIsCorporateSubscription($request);
                //dd($this->ViewData['corporaterecord']);
            }
        }        $this->ViewData['type']                = '0';
        return view($this->ViewFolder.'renew-subscription',$this->ViewData);
    }

    public function getIsCorporateSubscription($request)
    {
        //dd($request);
        $customers = CustomersModel::where('fk_company_id',$request['companyId'])
                                    ->where('id',$request['custid'])
                                    ->wherenull('deleted_at') 
                                    ->get();
                                    //dd($customers);

        if(sizeof($customers)>0)
        {
            $isCoporate = CompanyModel::find($request['companyId']);
            //dd($isCoporate);
            $arr_subscription = $arr_finalSubscription = [];
            if($isCoporate->is_corporate == 'yes') 
            {
                $getCorporate_details = CorporatesModel::where('name',$isCoporate->name)->get();
                //dd($request['carpark_id']);
                $getCoporateSubscription = CorporatesModel::
                                        select('corporates.fk_company_id','corporates.id','corporate_subscription.fk_carpark_subscription_id','corporate_subscription.fk_carpark_id')
                                        ->join('corporate_subscription','corporate_subscription.fk_corporate_id','corporates.id')
                                        //->where('corporates.fk_company_id',$request->companyId)
                                        // ->where('corporate_subscription.fk_carpark_subscription_id',$request->id)
                                        ->where('corporate_subscription.fk_carpark_id',$request['carpark_id'])  
                                        ->where('corporate_subscription.fk_corporate_id',$getCorporate_details[0]['id'])  
                                        ->wherenull('corporate_subscription.deleted_at')
                                        ->where('corporate_subscription.status','active')
                                        ->get();
                if(sizeof($getCoporateSubscription)>0)
                {
                    foreach ($getCoporateSubscription as $val) 
                    {
                        $collection  =  CarparkSubscriptionModel::
                                    where('subscription_type','corporate')
                                    ->where('id',$val['fk_carpark_subscription_id'])
                                    ->where('fk_carpark_id',$request['carpark_id']) 
                                    ->where('status','active')
                                    ->wherenull('deleted_at')
                                    ->get();
                        
                        if(sizeof($collection)>0)
                        {
                           $arr_subscription['id']           = $collection[0]->id;
                           $arr_subscription['name']         = $collection[0]->name;
                           $arr_subscription['payment_type'] = $collection[0]->payment_type;
                           $arr_finalSubscription[] = $arr_subscription;
                           $arr_subscription = [];
                        }            
                    }
                    
                    
                } 
                return $arr_finalSubscription;                                                   
            } 
        }
        else
        {
            $isCoporate = CompanyModel::find($request['companyId']);
            
            $arr_subscription = $arr_finalSubscription = [];
            if($isCoporate->is_corporate == 'yes') 
            {

                $getCoporateSubscription = CorporatesModel::
                                        select('corporates.fk_company_id','corporates.id','corporate_subscription.fk_carpark_subscription_id','corporate_subscription.fk_carpark_id')
                                        ->join('corporate_subscription','corporate_subscription.fk_corporate_id','corporates.id')
                                        ->where('corporates.fk_company_id',$request['companyId'])
                                        ->groupBy('corporates.fk_company_id','corporates.id','corporate_subscription.fk_carpark_subscription_id','corporate_subscription.fk_carpark_id')
                                        ->get();
                                 
                if(sizeof($getCoporateSubscription)>0)
                {
                    foreach ($getCoporateSubscription as $val) 
                    {
                        $collection  =  CarparkSubscriptionModel::
                                    where('subscription_type','corporate')
                                    ->where('id',$val['fk_carpark_subscription_id'])
                                    ->where('fk_carpark_id',$request['carpark_id']) 
                                    ->where('status','active')
                                    ->get();
                        
                        if(sizeof($collection)>0)
                        {
                           $arr_subscription['id']           = $collection[0]->id;
                           $arr_subscription['name']         = $collection[0]->name;
                           $arr_subscription['payment_type'] = $collection[0]->payment_type;
                           $arr_finalSubscription[] = $arr_subscription;
                           $arr_subscription = [];
                        }            
                    }
                    
                    
                } 
                return $arr_finalSubscription;                                                   
            } 
           //return ''; 
        }
    }
    public function getNextDate($collection)
    {
        //dd($collection);
        $time=strtotime($collection['to_date']);
        $month=date("m",$time);
        $d=cal_days_in_month(CAL_GREGORIAN,$month,date('Y'));
        //dd($d);
        $nextDate = [];
        $nextDate['from_date'] = date('d/m/Y', strtotime($collection->to_date));
        if($collection->duration_type == 'month')
        {
            $duration    = (int)$collection->duration ;
            $last_date   = strtotime(date("Y-m-d", strtotime($collection->to_date)) . " +".$duration." month");
            $expiry_date = Date('Y-m-d',$last_date);
            $nextDate['expiry_date'] = Date('d/m/Y',strtotime($expiry_date));
            
        }
        else if($collection->duration_type == 'day')
        {
            $expiry_date        = strtotime(date("Y-m-d", strtotime($collection->to_date)) . " +".(int)$collection->duration." day");
            $inactiveExpiryDate = Date('d-m-Y',strtotime($expiry_date));
            
            $nextDate['expiry_date'] = Date('d/m/Y',strtotime($expiry_date));
        }

        return $nextDate;
    }

    public function renewSubscription(Request $request)
    {
        //dd($request->all());
        $currentDate = date('Y-m-d');
        $request_from_date = $request_todate =  $request_old_start_date = null;
        if(!empty($request->to_date))
        {
            $request_expirty = explode('/', $request->to_date);
            $request_expirty = $request_expirty[2].'-'.$request_expirty[1].'-'.$request_expirty[0];
            $request_expirty = Date('Y-m-d',strtotime($request_expirty));

            $request_todate = Date('d-m-Y',strtotime($request_expirty));
            $request_todate = date('Y-m-d', strtotime($request_todate.' +1 day'));

            $request_from_date = explode('/', $request->from_date);
            $request_from_date = $request_from_date[2].'-'.$request_from_date[1].'-'.$request_from_date[0];
            //$previous_expiry_date = Date('d-m-Y',strtotime($request_from_date));
            $previous_expiry_date = date('Y-m-d', strtotime($request_from_date.' -1 day'));
            $previous_to_date = date('Y-m-d', strtotime($request_from_date));
            //dd($previous_expiry_date,$previous_to_date); 
            $request_from_date = Date('Y-m-d',strtotime($request_from_date));

            $request_old_start_date = explode('/', $request->old_start_date);
            $request_old_start_date = $request_old_start_date[2].'-'.$request_old_start_date[1].'-'.$request_old_start_date[0];

            $request_old_start_date = Date('Y-m-d',strtotime($request_old_start_date));
        }

        $details = CustomerCarparkModel::find($request->hd_id);

        if((strtotime($currentDate) == strtotime($request_from_date) || strtotime($currentDate) > strtotime($request_from_date)) && strtotime($currentDate) < strtotime($request_expirty))
        {
            $request_type = "active";
            /* inactivate previous subscription from history */
            CustomerHistory::where('fk_carpark_subscription_id',$details->fk_carpark_subscription)
                           ->where('fk_customer_id',$details->fk_customer_id)
                           ->where('status','active')
                           ->update([
                                'to_date'       => $previous_to_date,
                                'expiry_date'   => $previous_expiry_date,
                                'status'        => 'inactive'
                           ]);

            $details->fk_carpark_subscription = $request->carpark_subscription;
            $details->to_date     = $request_todate;
            $details->expiry_date = $request_expirty;
            $details->from_date   = $request_from_date;
            $details->rate        = $request->rate;
            $details->commission  = $request->commission;
            $details->save();
        }

        else if(strtotime($currentDate) < strtotime($request_expirty))
        {
            $request_type = "up_coming";
        }
        else if(strtotime($currentDate) > strtotime($request_from_date) && strtotime($currentDate) >strtotime($request_expirty))
        {
            $request_type = "inactive";
        }

        /* insert record in customer history in any case */
        $CustomerHistory                             = new CustomerHistory;
        $CustomerHistory->fk_customer_id             = $details->fk_customer_id;
        $CustomerHistory->fk_carpark_subscription_id = $request->carpark_subscription;
        $CustomerHistory->rate                       = $request->rate;
        $CustomerHistory->status                     = $request_type;
        $CustomerHistory->fk_carpark_id              = $details->fk_carpark_id; 
        $CustomerHistory->from_date                  = $request_from_date;
        $CustomerHistory->to_date                    = $request_todate;
        $CustomerHistory->expiry_date                = $request_expirty;
        $CustomerHistory->comment                    = "Subscription Renewal";
        $CustomerHistory->commission                 = $request->commission;

        if(!empty($request->specialRate))
        {
            $CustomerHistory->is_special_rate_applied   = 'yes';
        }
        else
        {
           $CustomerHistory->is_special_rate_applied   = 'no';
        }

        $CustomerHistory->fk_customer_carpark_id       = $details->id;
        
        $CustomerHistory->save();

        $revenue_details['from_date'] = $request_from_date;
        $revenue_details['expiry_date'] = $request_expirty;
        $revenue_details['rate'] = $request->rate;
        $revenue_details['vat'] = $request->vat;
        $revenue_details['fk_customer_id'] = $details->fk_customer_id;
        $revenue_details['customer_history_id'] = $CustomerHistory->id;
        $revenue_details['corporate_history_id'] = NULL;
        $revenue_details['commission'] = $request->commission;

        /* Generate invoice only for ST */
        if($request->payment_type == 'ST')
        {
            $settings = SettingsModel::first();
            $defaultVat = $settings->vat;
            $invoiceDate = date('Y-m-d');

            $calculateVat  = $this->CommonRepository->calculateVat($request->rate,$request->vat);
            $PayableAmount = $invoice_amount = $calculateVat['rate'];       
            $vat_amount         = $calculateVat['VatAmount'];
            $total_amount       = $calculateVat['TotalAmount'];
            $discounted_amount  = $credit_deducted = 0;

            $tag = 'P-';

            if($request->payment_type == 'ST')
            {
                $tag = 'S-';
            }
            else if($request->payment_type == 'YPS')
            {
                $tag = 'Y-';
            }

            /* Apply credits to invoice amount */
            $discounted_amount = $credit_deducted = $totalDiscount = 0 ;
            $CustomersModel = CustomersModel::find($details->fk_customer_id);
            if(!empty($CustomersModel->credit) && $CustomersModel->credit>0)
            {
                $discounted_amount = $credit_deducted = $totalDiscount = $CustomersModel->credit;
                if((float)$invoice_amount>=(float)$CustomersModel->credit)
                {
                    $invoice_amount = ((float)$invoice_amount-(float)$CustomersModel->credit);
                    $CustomersModel->credit = 0;
                    $CustomersModel->save();
                    if((float)$totalDiscount>=(float)$total_amount)
                    {
                        $PayableAmount = (float)$totalDiscount - (float)$total_amount;
                    }
                    else
                    {
                        $PayableAmount = (float)$total_amount - (float)$totalDiscount;
                    }
                }
                else if((float)$invoice_amount<(float)$CustomersModel->credit)
                {
                    $discounted_amount  = $credit_deducted = $totalDiscount = $invoice_amount;
                    $CustomersModel->credit = ((float)$CustomersModel->credit-(float)$invoice_amount);
                    $CustomersModel->save();
                    $invoice_amount = 0;
                    $PayableAmount = 0.00;
                }

                /* Update customer_credits */
                $CustomerCreditModel = new CustomerCredit;
                $CustomerCreditModel->fk_customer_id   = $CustomersModel->id;
                $CustomerCreditModel->credit_value     = $discounted_amount;
                $CustomerCreditModel->transaction_date = now();
                $CustomerCreditModel->transaction_type = 'redeem';
                $CustomerCreditModel->comment = "You have discounted Of amount".$credit_deducted;
                $CustomerCreditModel->customer_invoice_id = 0;
                $CustomerCreditModel->created_at = now();
                $CustomerCreditModel->created_by = Auth::id();
                $CustomerCreditModel->save();
            }

            $invName = createInvoiceNumber(trim($CustomersModel->client_no),$currentDate);
            $latestInvoice=$this->CommonRepository->latestInvoiceOfCurrentMonth($CustomersModel->id,false,$tag);
            
            if(!empty($latestInvoice))
            {
                $invName=$latestInvoice;
                $invName=recreateInvoiceNumberPath($invName);
            }
            else
            {
                $invName=$tag.$invName;
            }

            //dd($invName);
            $invPdf  = $invName.'.pdf';
            $DirectoryPath    = trim($CustomersModel->client_no).strtoupper(Date('/M-Y/'));
            $invPath = '/storage/Invoice/'.$DirectoryPath.$invPdf;
            $InvoicePdfPath   = $this->InvoicePdfPath.$DirectoryPath;
            $InvoiceStorePath = $InvoicePdfPath.$invPdf;
            //dd($InvoicePdfPath);
            $dueDate = $this->CommonRepository->calculateDueDate($CustomersModel);
            $CustomerInvoiceModel                   = new CustomerInvoiceModel;
            $CustomerInvoiceModel->fk_customer_id   = $CustomersModel->id;
            $CustomerInvoiceModel->invoice_no       = $invName;
            $CustomerInvoiceModel->invoice_pdf_name = $invPdf;
            $CustomerInvoiceModel->invoice_pdf_path = $invPath;
            $CustomerInvoiceModel->invoice_date     = now();
            $CustomerInvoiceModel->due_date         = $dueDate;
            $CustomerInvoiceModel->amount_paid      = 0;
            $CustomerInvoiceModel->status           = 'unpaid';
            $CustomerInvoiceModel->mail_status      = 'pending';
            $CustomerInvoiceModel->created_at       = now();
            $CustomerInvoiceModel->created_by       = Auth::id();
            $CustomerInvoiceModel->invoice_amount         = (float)$invoice_amount;
            $CustomerInvoiceModel->invoice_total_discount = (float)$totalDiscount;
            $CustomerInvoiceModel->invoice_vat_amount     = $vat_amount;
            $CustomerInvoiceModel->invoice_total_amount   = $total_amount;
            $CustomerInvoiceModel->commission     = $request->commission;
            $CustomerInvoiceModel->credit_deducted = (float)$totalDiscount;
            $CustomerInvoiceModel->generated_invoice = 1;
            $CustomerInvoiceModel->save();

            $revenue_details['fk_invoice_id'] = $CustomerInvoiceModel->id;

            /*----generate revenue------*/
            $this->CommonRepository->generateRevenue($revenue_details);

            /* Save Invoice Details */
            $InvoiceDetailsModel = new InvoiceDetailsModel;
            $InvoiceDetailsModel->fk_invoice_id = $CustomerInvoiceModel->id;
            $InvoiceDetailsModel->fk_carpark_id = $details->fk_carpark_id;

            $InvoiceDetailsModel->amount       = $invoice_amount;
            $InvoiceDetailsModel->vat          = $vat_amount;
            $InvoiceDetailsModel->total_amount = $total_amount;

            $InvoiceDetailsModel->credit       = 0;
            $InvoiceDetailsModel->created_at   = now();
            $InvoiceDetailsModel->created_by   = Auth::id();
            $InvoiceDetailsModel->fk_corporate_subscription_id = $request->carpark_subscription;

            $InvoiceDetailsModel->save();     

            /* Generate pdf */

            $invoiceDetails = [];
            $date           = collect([]) ;
            $date->from     = $request_from_date;
            $date->to       = $request_todate;
            $invoiceDetails['customerDate']        = $date;

            $sub_details = CarparkSubscriptionModel::find($request->carpark_subscription);

            $amt['carpark_name'] = $request->carpark;
            $amt['carpark_subscription_name'] = $sub_details->name;

            $amt['expiry_date'] = $request_expirty;  
            $amt['date_to']     = $date->to;  
            $amt['date_from']   = $date->from;  

            $amt['rate']        = $calculateVat['rate'];  
            $amt['VatAmount']   = $calculateVat['VatAmount'];
            $amt['TotalAmount'] = $calculateVat['TotalAmount'];
            $amt['duration']    = $request->duration;

            $arr1[] = $amt;

            $invoiceDetails['arr1']                = $arr1;
            $invoiceDetails['customerNetAmount']   = $invoice_amount;
            $invoiceDetails['customerVatAmount']   = $vat_amount;
            $invoiceDetails['customerTotalAmount'] = $total_amount;
            $invoiceDetails['invoice_total_discount'] = $totalDiscount;
            $invoiceDetails['PayableAmount']          = $PayableAmount;  

            $invoiceDetails['type']            = 'st-customer';
            $invoiceDetails['defaultVat']      = $defaultVat;
            $invoiceDetails['customer']        = $CustomersModel;

            $customerDetails['fname']      = $CustomersModel->first_name;
            $customerDetails['lname']      = $CustomersModel->last_name;
            $customerDetails['email']      = $CustomersModel->email;
            $customerDetails['client_no']  = $CustomersModel->client_no;

            $reg_no     = CustomerVehicalRegModel::where('status','active')
                                            ->where('fk_customer_id',$CustomersModel->id)
                                            ->get();
            $rcnt = 0;
            foreach ($reg_no as $value)
            {
                $regDetails[$rcnt] = $value['vehicle_registration_number'];
                $rcnt++;
            }
            $customerDetails['reg_no']     = $regDetails;
            
            $invoiceDetails['customerDetails'] = $customerDetails;
            $res[] = $request->carpark;
            $invoiceDetails['customerCarparks']= $res;
            $invoiceDetails['customerInvoice'] = $CustomerInvoiceModel;
            $invoiceDetails['carpark_name']    = $request->carpark;

            $invoiceDetails['from_date']       = $date->from;
            $invoiceDetails['to_date']         = $date->to;
            $invoiceDetails['expiry_date']     = $date->to;
            $invoiceDetails['rate']            = $CustomerInvoiceModel->invoice_vat_amount;

            $corporate_name = CorporatesModel::where('id',$CustomersModel->fk_corporate_id)->value('name');
            $invoiceDetails['corporatename']   = $corporate_name;
            $invoiceDetails['settings'] = $settings;

            /* discounted amount inc vat changes*/

                $defaultVat                = SettingsModel::value("vat");
                $vatDivisor                = 1 + ($defaultVat / 100); 
                $disBeforeVat            = $totalDiscount / $vatDivisor ;
                $invoiceDetails['vat_amount']= floatval($totalDiscount) - floatval($disBeforeVat);
                $TotalDis = floatval(preg_replace("/[^0-9\.]/", '',$totalDiscount));  
                $invoiceDetails['disBeforeVat']= floatval(preg_replace("/[^0-9\.]/", '',$disBeforeVat)); 
                $invoiceDetails['PayableBeforeVat'] = $invoice_amount-$disBeforeVat;

                $invoiceDetails['PayableVat'] = $vat_amount-$invoiceDetails['vat_amount'];  

            /* discounted amount inc vat changes end*/
        
       

            if (!file_exists($InvoicePdfPath)) 
            { 
                mkdir($InvoicePdfPath, 0777, true); 
            }
            
            NPDF::loadView($this->InvoicePath,$invoiceDetails)->save($InvoiceStorePath);
            /*if($response['invoiceId']!='')
            {*/
                return redirect('corporates/sendMailWithSubject/'.$CustomerInvoiceModel->id.'/customer');
            /*}
            else
            {
                return redirect($redirect)
                ->with(['success' => 'Subscription Updated Successfully']);
            }   */    
        }
    }

    public function getRate(Request $request)
    {
        $response  = $this->CommonRepository->getCopSubscriptionRate($request->sub_id);
        
        $arr_subscription['rate']          = $response->rate;
        $arr_subscription['duration']      = $response->duration;
        $arr_subscription['duration_type'] = $response->duration_type;
        $arr_subscription['payment_type']  = $response->payment_type;
        $arr_subscription['subscription_type']  = $response->subscription_type;
        $arr_subscription['vat_included']  = $response->vat_included;
        $effectiveDate = $this->getEffectiveDate($request->id);
        $arr_subscription['effectiveDate']  = $effectiveDate;
        $arr_date = array('effectiveDate'=> $effectiveDate);
        
        (array_push($arr_subscription,$effectiveDate));
    
        return $arr_subscription;
    }

    public function getEffectiveDate($id)
    {
        $effectiveStartDate = '';
        $getdetails = CustomerCarparkModel::find($id);
    
        if($getdetails)
        {
            if(!empty($getdetails->to_date))
            {
                $inactiveExpiryDate = Date('d-m-Y',strtotime($getdetails->to_date));
            }
            else
            {
                $inactiveExpiryDate = Date('d-m-Y',strtotime($getdetails->from_date));
            }               
        }   
        else
        {
            $inactiveExpiryDate = null;
        }       
                 
        return $inactiveExpiryDate;                  
    }

    public function getChanges(Request $request)
    {
        /*if($request->is_corporate)
        {
            dd("ho");
        }
        else
        {
            dd("nahi");
        }*/
        try
        {
            if($request->type == 'cancel')
            {
                $response = $this->CommonRepository->cancelSubscription($request,false);   
            }
            /*else
            {
                $response = $this->CommonRepository->getcorporateChanges($request);
            }*/
            return response()->json($response);
        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return redirect(str_plural($this->ModelPath))
                ->with(['error' => Lang::get('custom.something_wrong')])
                ->withInput();
        }
    }

    function inactivateTodayCustomers()
    {
        $customers = CustomerHistory::where('status','inactive')
                                    ->whereDate('to_date',date('Y-m-d'))
                                    ->get();
        if(!$customers->isEmpty())
        {
            foreach ($customers as $key => $customer) 
            {
                CustomerCarparkModel::where('fk_customer_id',$customer['fk_customer_id'])
                                    ->where('fk_carpark_id',$customer['fk_carpark_id'])
                                    ->where('fk_carpark_subscription',$customer['fk_carpark_subscription_id'])
                                    ->whereDate('to_date',date('Y-m-d'))
                                    ->update([
                                        'status'=>'inactive'
                                    ]);

            }
        }
    }

    function InsertTodayActivatedRegInSki()
    {
        $reg_numbers = CustomerVehicalRegModel::whereDate('effective_from',date('Y-m-d'))
                                                ->where('status','active')
                                                ->get();
        foreach ($reg_numbers as $key => $reg) 
        {
            $client_no = CustomersModel::where('id',$reg['vehicle_registration_number'])->value('client_no');

            $carparks =  CustomerCarparkModel::join('car_parks','car_parks.id','customer_carpark.fk_carpark_id')
                    ->where('fk_customer_id',$reg['fk_customer_id'])
                    ->where('customer_carpark.status','active')
                    ->WhereNotNull('facility_no')
                    ->get(['fk_carpark_id','facility_no','ski_carpark_no','from_date','expiry_date']);

            if(!$carparks->isEmpty())
            {
                foreach ($carparks as $key => $res) 
                {
                    $data['APIKey']         = $this->CommonRepository->getSkiApiKey();
                    /*$data['FacilityNo']   = $res['facility_no'];
                    $data['ValidCarparks']  = [$res['ski_carpark_no']];*/
                    $data['FacilityNo']     = '550012';
                    $data['ValidCarparks']  = [0];
                    $data['TicketNo']       = $reg['vehicle_registration_number'];
                    $data['TicketType']     = 4;
                    $data['ProductId']      = "PCPMT";
                    $data['ValidFrom']      = $res['from_date'];
                    $data['ValidUntil']     = $res['expiry_date'];
                    $data['ReferenceNo']    = $client_no;

                    $this->CommonRepository->makeCurlCallToSki('CreateSingleUseIdentifier',$data,'Upcoming Reg Active.',$reg['vehicle_registration_number'],$res['fk_carpark_id'],$reg['fk_customer_id']);   
                }
            }
        }

    }

    function skitest(Request $request)
    {
        // api key = VqImbNYc24wyhaPOjSaCSSNviAn4kZtsC1KTKWP5XJOiMNTsELZy2bX10gtxCCBUqzN2BhMrcZDLUWZ9I8mQ
        /*{"FacilityNo":550012,"CarparkNo":0,"TicketNo":"12345","TicketType":4,"ReferenceNo":"5KEi6yI9DGolupNYwUWf","CompletionTime":"2021-11-22T12:46:57Z","RateNo":1,"ParkingDuration":2,"Amount":0,"EventID":"90de44cb-dbfa-498c-b2b7-0f9c5e4d9ad7"}*/
        DB::table('ski_test')->insert([
            'response' => $request->response
        ]);
        /*$client = new \WebSocket\Client("wss://live-apt-mobileapi.azurewebsites.net/api/TransactionMonitor?APIKey=VqImbNYc24wyhaPOjSaCSSNviAn4kZtsC1KTKWP5XJOiMNTsELZy2bX10gtxCCBUqzN2BhMrcZDLUWZ9I8mQ",['timeout' => 60]);
           
            dd(json_decode($client->receive()));*/
    }


    function makeAccountDetailEntries()
    {
        $data = array(
            array('invoice_id'=>'S-KM028541-AUG-2021',
                  'month_year'=>'Dec-2021',
                  'revenue'=>'141.55',
                  'commission'=>'113.24'
            ),
            array('invoice_id'=>'S-KM028597-AUG-2021',
                    'month_year'=>'Dec-2021',
                    'revenue'=>'141.55',
                  'commission'=>'113.24'
            ),
            array('invoice_id'=>'S-KM1000722-SEP-2021',
                  'month_year'=>'Dec-2021',
                  'revenue'=>'139.26',
                  'commission'=>'111.41'
            ),
            array('invoice_id'=>'S-KM030545-SEP-2021',
                    'month_year'=>'Dec-2021',
                    'revenue'=>'137.05',
                  'commission'=>'109.64'
            ),
            array('invoice_id'=>'S-2-ORCC00745-OCT-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-OCT-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-SEP-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-SEP-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00710-SEP-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'4281.96',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00754-SEP-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'1362.44',
                'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-AUG-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00740-AUG-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'90.24',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR023222-AUG-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'97.32',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC027385-JUL-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'106.16',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUL-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR026585-JUN-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'97.32',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUN-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'1031.98',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR026361-JUN-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'97.32',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC025834-MAY-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'106.16',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC025657-MAY-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'106.16',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00740-APR-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'90.24',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR1000371-APR-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'97.32',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00729-JAN-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'176.94',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUL-2021',
                'month_year'=>'Dec-2021',
                'revenue'=>'172.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-KM028541-AUG-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'141.55',
                  'commission'=>'113.24'
            ),
            array('invoice_id'=>'S-KM028597-AUG-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'141.55',
                  'commission'=>'113.24'
            ),
            array('invoice_id'=>'S-KM1000722-SEP-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'139.26',
                  'commission'=>'111.41'
            ),
            array('invoice_id'=>'S-KM030545-SEP-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'137.05',
                  'commission'=>'109.64'
            ),
            array('invoice_id'=>'S-2-ORCC00745-OCT-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-OCT-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-SEP-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-SEP-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00710-SEP-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'4281.96',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00754-SEP-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'1362.44',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-AUG-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00740-AUG-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'90.24',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR023222-AUG-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'97.32',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC027385-JUL-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'106.16',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUL-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR026585-JUN-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'97.32',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUN-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'1031.98',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR026361-JUN-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'97.32',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC025834-MAY-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'106.16',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC025657-MAY-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'106.16',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00740-APR-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'90.24',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR1000371-APR-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'97.32',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00729-JAN-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'176.94',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUL-2021',
                'month_year'=>'Jan-2022',
                'revenue'=>'172.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-KM028541-AUG-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'127.85',
                  'commission'=>'102.28'
            ),
            array('invoice_id'=>'S-KM028597-AUG-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'127.85',
                  'commission'=>'102.28'
            ),
            array('invoice_id'=>'S-KM1000722-SEP-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'125.79',
                  'commission'=>'100.63'
            ),
            array('invoice_id'=>'S-KM030545-SEP-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'123.78',
                  'commission'=>'99.03'
            ),
            array('invoice_id'=>'S-2-ORCC00745-OCT-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'77.68',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-OCT-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'77.68',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-SEP-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'77.86',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-SEP-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'77.86',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00710-SEP-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'3867.58',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00754-SEP-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'1230.59',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-AUG-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'77.68',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00740-AUG-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'81.51',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR023222-AUG-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'87.90',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC027385-JUL-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'95.89',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUL-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'77.68',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR026585-JUN-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'87.90',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUN-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'932.11',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR026361-JUN-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'87.90',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC025834-MAY-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'95.89',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC025657-MAY-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'95.89',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00740-APR-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'81.51',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR1000371-APR-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'87.90',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUL-2021',
                'month_year'=>'Feb-2022',
                'revenue'=>'155.35',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-KM028541-AUG-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'141.55',
                  'commission'=>'113.24'
            ),
            array('invoice_id'=>'S-KM028597-AUG-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'141.55',
                  'commission'=>'113.24'
            ),
            array('invoice_id'=>'S-KM1000722-SEP-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'139.26',
                  'commission'=>'111.41'
            ),
            array('invoice_id'=>'S-KM030545-SEP-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'137.05',
                  'commission'=>'109.64'
            ),
            array('invoice_id'=>'S-2-ORCC00745-OCT-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-OCT-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-SEP-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-SEP-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00710-SEP-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'4281.96',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00754-SEP-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'1362.44',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-AUG-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00740-AUG-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'90.24',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR023222-AUG-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'97.32',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC027385-JUL-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'106.16',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUL-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR026585-JUN-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'97.32',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUN-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'1031.98',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR026361-JUN-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'97.32',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC025834-MAY-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'106.16',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC025657-MAY-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'106.16',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00740-APR-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'90.24',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR1000371-APR-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'97.32',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUL-2021',
                'month_year'=>'Mar-2022',
                'revenue'=>'172.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-KM028541-AUG-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'136.99',
                  'commission'=>'109.59'
            ),
            array('invoice_id'=>'S-KM028597-AUG-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'136.99',
                  'commission'=>'109.59'
            ),
            array('invoice_id'=>'S-KM1000722-SEP-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'134.77',
                  'commission'=>'107.82'
            ),
            array('invoice_id'=>'S-KM030545-SEP-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'132.63',
                  'commission'=>'106.10'
            ),
            array('invoice_id'=>'S-2-ORCC00745-OCT-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'83.22',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-OCT-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'83.22',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-SEP-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'83.22',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-SEP-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'83.22',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00710-SEP-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'4143.84',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00754-SEP-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'1318.49',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-AUG-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'83.22',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00740-AUG-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'75.68',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR023222-AUG-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'94.18',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC027385-JUL-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'102.74',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUL-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'83.22',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR026585-JUN-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'94.18',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUN-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'998.69',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR026361-JUN-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'94.18',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC025834-MAY-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'102.74',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC025657-MAY-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'102.74',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00740-APR-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'75.68',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR1000371-APR-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'34.53',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUL-2021',
                'month_year'=>'Apr-2022',
                'revenue'=>'166.45',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-KM028541-AUG-2021',
                'month_year'=>'May-2022',
                'revenue'=>'141.55',
                  'commission'=>'113.24'
            ),
            array('invoice_id'=>'S-KM028597-AUG-2021',
                'month_year'=>'May-2022',
                'revenue'=>'141.55',
                  'commission'=>'113.24'
            ),
            array('invoice_id'=>'S-KM1000722-SEP-2021',
                'month_year'=>'May-2022',
                'revenue'=>'139.26',
                  'commission'=>'111.41'
            ),
            array('invoice_id'=>'S-KM030545-SEP-2021',
                'month_year'=>'May-2022',
                'revenue'=>'137.05',
                  'commission'=>'109.64'
            ),
            array('invoice_id'=>'S-2-ORCC00745-OCT-2021',
                'month_year'=>'May-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-OCT-2021',
                'month_year'=>'May-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-SEP-2021',
                'month_year'=>'May-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-SEP-2021',
                'month_year'=>'May-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00710-SEPT-2021',
                'month_year'=>'May-2022',
                'revenue'=>'4281.96',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00754-SEP-2021',
                'month_year'=>'May-2022',
                'revenue'=>'1362.44',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-AUG-2021',
                'month_year'=>'May-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR023222-AUG-2021',
                'month_year'=>'May-2022',
                'revenue'=>'97.32',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC027385-JUL-2021',
                'month_year'=>'May-2022',
                'revenue'=>'106.16',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUL-2021',
                'month_year'=>'May-2022',
                'revenue'=>'86.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR026585-JUN-2021',
                'month_year'=>'May-2022',
                'revenue'=>'97.32',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUN-2021',
                'month_year'=>'May-2022',
                'revenue'=>'1031.98',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR026361-JUN-2021',
                'month_year'=>'May-2022',
                'revenue'=>'97.32',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC025834-MAY-2021',
                'month_year'=>'May-2022',
                'revenue'=>'58.22',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC025657-MAY-2021',
                'month_year'=>'May-2022',
                'revenue'=>'30.82',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUL-2021',
                'month_year'=>'May-2022',
                'revenue'=>'172.00',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-KM028541-AUG-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'136.99',
                  'commission'=>'109.59'
            ),
            array('invoice_id'=>'S-KM028597-AUG-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'136.99',
                  'commission'=>'109.59'
            ),
            array('invoice_id'=>'S-KM1000722-SEP-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'134.77',
                  'commission'=>'107.82'
            ),
            array('invoice_id'=>'S-KM030545-SEP-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'136.63',
                  'commission'=>'106.10'
            ),
            array('invoice_id'=>'S-2-ORCC00745-OCT-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'83.22',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-OCT-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'83.22',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-SEP-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'83.22',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-SEP-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'83.22',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00710-SEP-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'4143.84',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00754-SEP-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'1318.49',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-AUG-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'83.22',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR023222-AUG-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'94.18',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC027385-JUL-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'102.74',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUL-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'83.22',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR026585-JUN-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'69.06',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUN-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'998.69',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR026361-JUN-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'40.81',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUL-2021',
                'month_year'=>'Jun-2022',
                'revenue'=>'166.45',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-KM028541-AUG-2021',
                'month_year'=>'Jul-2022',
                'revenue'=>'141.55',
                  'commission'=>'113.24'
            ),
            array('invoice_id'=>'S-KM028597-AUG-2021',
                'month_year'=>'Jul-2022',
                'revenue'=>'141.55',
                  'commission'=>'113.24'
            ),
            array('invoice_id'=>'S-KM1000722-SEP-2021',
                'month_year'=>'Jul-2022',
                'revenue'=>'139.26',
                  'commission'=>'111.41'
            ),
            array('invoice_id'=>'S-KM030545-SEP-2021',
                'month_year'=>'Jul-2022',
                'revenue'=>'137.05',
                  'commission'=>'109.64'
            ),
            array('invoice_id'=>'S-2-ORCC00745-OCT-2021',
                'month_year'=>'Jul-2022',
                'revenue'=>'49.93',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-OCT-2021',
                'month_year'=>'Jul-2022',
                'revenue'=>'49.93',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-SEP-2021',
                'month_year'=>'Jul-2022',
                'revenue'=>'49.93',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-SEP-2021',
                'month_year'=>'Jul-2022',
                'revenue'=>'49.93',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00710-SEP-2021',
                'month_year'=>'Jul-2022',
                'revenue'=>'4281.96',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00754-SEP-2021',
                'month_year'=>'Jul-2022',
                'revenue'=>'1362.44',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-1-ORCC00745-AUG-2021',
                'month_year'=>'Jul-2022',
                'revenue'=>'49.93',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR023222-AUG-2021',
                'month_year'=>'Jul-2022',
                'revenue'=>'97.32',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC027385-JUL-2021',
                'month_year'=>'Jul-2022',
                'revenue'=>'106.16',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUL-2021',
                'month_year'=>'Jul-2022',
                'revenue'=>'49.93',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUN-2021',
                'month_year'=>'Jul-2022',
                'revenue'=>'599.22',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00745-JUL-2021',
                'month_year'=>'Jul-2022',
                'revenue'=>'99.87',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-KM028541-AUG-2021',
                'month_year'=>'Aug-2022',
                'revenue'=>'127.85',
                  'commission'=>'102.28'
            ),
            array('invoice_id'=>'S-KM028597-AUG-2021',
                'month_year'=>'Aug-2022',
                'revenue'=>'73.06',
                  'commission'=>'58.45'
            ),
            array('invoice_id'=>'S-KM1000722-SEP-2021',
                'month_year'=>'Aug-2022',
                'revenue'=>'139.26',
                  'commission'=>'111.41'
            ),
            array('invoice_id'=>'S-KM030545-SEP-2021',
                'month_year'=>'Aug-2022',
                'revenue'=>'137.05',
                  'commission'=>'109.64'
            ),
            array('invoice_id'=>'S-ORCC00710-SEP-2021',
                'month_year'=>'Aug-2022',
                'revenue'=>'4281.96',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-ORCC00754-SEP-2021',
                'month_year'=>'Aug-2022',
                'revenue'=>'1362.44',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-OR023222-AUG-2021',
                'month_year'=>'Aug-2022',
                'revenue'=>'31.39',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-CC027385-JUL-2021',
                'month_year'=>'Aug-2022',
                'revenue'=>'65.07',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-KM1000722-SEP-2021',
                'month_year'=>'Sep-2022',
                'revenue'=>'58.40',
                  'commission'=>'46.72'
            ),
            array('invoice_id'=>'S-KM030545-SEP-2021',
                'month_year'=>'Sep-2022',
                'revenue'=>'132.63',
                  'commission'=>'106.10'
            ),
            array('invoice_id'=>'S-ORCC00754-SEP-2021',
                'month_year'=>'Sep-2022',
                'revenue'=>'1318.49',
                  'commission'=>''
            ),
            array('invoice_id'=>'S-KM030545-SEP-2021',
                'month_year'=>'Oct-2022',
                'revenue'=>'48.63',
                  'commission'=>'38.90'
            ),
            array('invoice_id'=>'S-ORCC00754-SEP-2021',
                'month_year'=>'Oct-2022',
                'revenue'=>'131.85',
                  'commission'=>''
            )
        );
        $count = 0;
        foreach ($data as $key => $value) 
        {
            $invoice_id = CustomerInvoiceModel::where('invoice_no',$value['invoice_id'])->value('id');
            $fk_st_revenue_id = SeasonTicketRevenueModel::where('fk_invoice_id',$invoice_id)->value('id');
            $count++;
            //echo $fk_st_revenue_id;
            $RevenueDetailsModel = new RevenueDetailsModel;
            $RevenueDetailsModel->fk_st_revenue_id = $fk_st_revenue_id;
            $RevenueDetailsModel->month_year = $value['month_year'];
            $RevenueDetailsModel->revenue = $value['revenue'];
            $RevenueDetailsModel->revenue_commission = $value['commission'];
            $RevenueDetailsModel->created_by = auth()->user()->id;
            $RevenueDetailsModel->updated_by = auth()->user()->id;
            $RevenueDetailsModel->save();
        }
        //dd($count);
        //exit();
    }

    function makeAccountEntries()
    {
        $data = array(
            array('invoice_no'=>'S-KM028541-AUG-2021',
                    'revenue'=>'1666.67',
                    'commission'=>'1333.33',
                    'from_date'=>'2021-08-16',
                    'expiry_date'=>'2022-08-28',
                    'carpark'=>'10'
            ),
            array('invoice_no'=>'S-KM028597-AUG-2021',
                'revenue'=>'1666.67',
                'commission'=>'1333.33',
                'from_date'=>'2021-08-17',
                'expiry_date'=>'2022-08-16',
                'carpark'=>'10'
            ),
            array('invoice_no'=>'S-KM1000722-SEP-2021',
                'revenue'=>'1666.67',
                'commission'=>'1333.33',
                'from_date'=>'2021-09-08',
                'expiry_date'=>'2022-09-13',
                'carpark'=>'10'
            ),
            array('invoice_no'=>'S-KM030545-SEP-2021',
                'revenue'=>'1666.67',
                'commission'=>'1333.33',
                'from_date'=>'2021-09-30',
                'expiry_date'=>'2022-10-11',
                'carpark'=>'10'
            ),
            array('invoice_no'=>'S-2-ORCC00745-OCT-2021',
                'revenue'=>'768.44',
                'commission'=>'',
                'from_date'=>'2021-10-15',
                'expiry_date'=>'2022-07-18',
                'carpark'=>'1'
            ),
            array('invoice_no'=>'S-1-ORCC00745-OCT-2021',
                'revenue'=>'793.41',
                'commission'=>'',
                'from_date'=>'2021-10-06',
                'expiry_date'=>'2022-07-18',
                'carpark'=>'1'
            ),
            array('invoice_no'=>'S-1-ORCC00745-SEP-2021',
                'revenue'=>'776.76',
                'commission'=>'',
                'from_date'=>'2021-10-12',
                'expiry_date'=>'2022-07-18',
                'carpark'=>'1'
            ),
            array('invoice_no'=>'S-ORCC00745-SEP-2021',
                'revenue'=>'807.28',
                'commission'=>'',
                'from_date'=>'2021-10-01',
                'expiry_date'=>'2022-07-18',
                'carpark'=>'1'
            ),
            array('invoice_no'=>'S-ORCC00710-SEP-2021',
                'revenue'=>'50416.67',
                'commission'=>'',
                'from_date'=>'2021-09-01',
                'expiry_date'=>'2022-08-31',
                'carpark'=>'1'
            ),
            array('invoice_no'=>'S-ORCC00754-SEP-2021',
                'revenue'=>'16041.67',
                'commission'=>'',
                'from_date'=>'2021-10-04',
                'expiry_date'=>'2022-10-03',
                'carpark'=>'1'
            ),
            array('invoice_no'=>'S-1-ORCC00745-AUG-2021',
                'revenue'=>'890.50',
                'commission'=>'',
                'from_date'=>'2021-09-01',
                'expiry_date'=>'2022-07-18',
                'carpark'=>'1'
            ),
            array('invoice_no'=>'S-ORCC00740-AUG-2021',
                'revenue'=>'730.65',
                'commission'=>'',
                'from_date'=>'2021-08-19',
                'expiry_date'=>'2022-04-26',
                'carpark'=>'1'
            ),
            array('invoice_no'=>'S-OR023222-AUG-2021',
                'revenue'=>'1145.83',
                'commission'=>'',
                'from_date'=>'2021-08-11',
                'expiry_date'=>'2022-08-10',
                'carpark'=>'1'
            ),
            array('invoice_no'=>'S-CC027385-JUL-2021',
                'revenue'=>'1250.00',
                'commission'=>'',
                'from_date'=>'2021-08-20',
                'expiry_date'=>'2022-08-19',
                'carpark'=>'9'
            ),
            array('invoice_no'=>'S-ORCC00745-JUL-2021',
                'revenue'=>'1062.50',
                'commission'=>'',
                'from_date'=>'2021-07-01',
                'expiry_date'=>'2022-07-18',
                'carpark'=>'1'
            ),
            array('invoice_no'=>'S-OR026585-JUN-2021',
                'revenue'=>'1145.83',
                'commission'=>'',
                'from_date'=>'2021-06-23',
                'expiry_date'=>'2022-06-22',
                'carpark'=>'1'
            ),
            array('invoice_no'=>'S-ORCC00745-JUN-2021',
                'revenue'=>'12750.00',
                'commission'=>'',
                'from_date'=>'2021-07-01',
                'expiry_date'=>'2022-07-18',
                'carpark'=>'1'
            ),
            array('invoice_no'=>'S-OR026361-JUN-2021',
                'revenue'=>'1145.83',
                'commission'=>'',
                'from_date'=>'2021-06-14',
                'expiry_date'=>'2022-06-13',
                'carpark'=>'1'
            ),
            array('invoice_no'=>'S-CC025834-MAY-2021',
                'revenue'=>'1250.00',
                'commission'=>'',
                'from_date'=>'2021-05-18',
                'expiry_date'=>'2022-05-17',
                'carpark'=>'9'
            ),
            array('invoice_no'=>'S-CC025657-MAY-2021',
                'revenue'=>'1250.00',
                'commission'=>'',
                'from_date'=>'2021-05-10',
                'expiry_date'=>'2022-05-09',
                'carpark'=>'9'
            ),
            array('invoice_no'=>'S-ORCC00740-APR-2021',
                'revenue'=>'1062.50',
                'commission'=>'',
                'from_date'=>'2021-04-27',
                'expiry_date'=>'2022-04-26',
                'carpark'=>'1'
            ),
            array('invoice_no'=>'S-OR1000371-APR-2021',
                'revenue'=>'1145.83',
                'commission'=>'',
                'from_date'=>'2021-04-12',
                'expiry_date'=>'2022-04-11',
                'carpark'=>'1'
            ),
            array('invoice_no'=>'S-ORCC00729-JAN-2021',
                'revenue'=>'2083.33',
                'commission'=>'',
                'from_date'=>'2021-02-01',
                'expiry_date'=>'2022-01-31',
                'carpark'=>'1'
            ),
            array('invoice_no'=>'S-ORCC00745-JUL-2021',
                'revenue'=>'1869.78',
                'commission'=>'',
                'from_date'=>'2021-08-16',
                'expiry_date'=>'2022-07-18',
                'carpark'=>'1'
            )
        );

        foreach ($data as $key => $value) 
        {
            //dd($value);
            $invoiceDetails = CustomerInvoiceModel::select('id','fk_customer_id','fk_corporate_id')
                                                ->where('invoice_no',$value['invoice_no'])
                                                ->first();
           // dd($invoiceDetails->fk_customer_id);
            $fk_customer_id = '';
            $fk_corporate_history_id = NULL;
            $fk_customer_history_id = NULL;

            $fk_invoice_id = $invoiceDetails->id;

            if(empty($invoiceDetails->fk_customer_id))
            {
                $fk_customer_id = $invoiceDetails->fk_corporate_id;
                $fk_corporate_history_id = CorporateHistoryModel::whereDate('from_date',$value['from_date'])
                ->whereDate('expiry_date',$value['expiry_date'])
                ->where('fk_carpark_id',$value['carpark'])
                ->where('fk_corporate_id',$fk_customer_id)
                ->value('id');
                //dd($fk_corporate_history_id);
                //echo $fk_corporate_history_id."<br>";
            }
            else
            {
                //dd($value);
                $fk_customer_id = $invoiceDetails->fk_customer_id;
                $fk_customer_history_id = CustomerHistory::whereDate('from_date',$value['from_date'])
                ->whereDate('expiry_date',$value['expiry_date'])
                ->where('fk_carpark_id',$value['carpark'])
                ->where('fk_customer_id',$fk_customer_id)
                ->value('id');
                //dd($fk_customer_history_id);
                //echo $fk_customer_history_id."<br>";
            }

            $SeasonTicketRevenueModel = new SeasonTicketRevenueModel;
            $SeasonTicketRevenueModel->fk_customer_history_id = $fk_customer_history_id;
            $SeasonTicketRevenueModel->fk_corporate_history_id = $fk_corporate_history_id;
            $SeasonTicketRevenueModel->fk_customer_id = $fk_customer_id;
            $SeasonTicketRevenueModel->fk_invoice_id = $fk_invoice_id;
            $SeasonTicketRevenueModel->total_revenue = $value['revenue'];
            $SeasonTicketRevenueModel->total_revenue_commission = $value['commission'];
            $SeasonTicketRevenueModel->save();
        }   
        //exit();
    }

    function getSkiTransactionEvents()
    {
        try
        {
            $api_key = $this->CommonRepository->getSkiApiKey();

            $client = new \WebSocket\Client("wss://live-apt-mobileapi.azurewebsites.net/api/TransactionMonitor?APIKey=".$api_key,['timeout' => 60]);

            $main_response = $client->receive();
            
            $response = json_decode($main_response);

            if(empty(@$response->Heartbeat))
            { 
                $carpark_no = CarParksModel::where('facility_no',$response->FacilityNo)
                                        ->where('ski_carpark_no',$response->CarparkNo)
                                        ->value('id');

                $customer_id = CustomersModel::where('client_no',$response->ReferenceNo)->value('id');

                $client_id = CarparkclientIdModel::where('fk_customer_id',$customer_id)->value('client_id');

                $pcp_client_id = $response->ReferenceNo;

                $transaction_type = 'Entry';
                $transaction_date = '';
                if(!empty(@$response->CompletionTime))
                {
                    $transaction_type = 'Exit';
                    $transaction_date = $response->CompletionTime;
                }
                else
                {
                    $transaction_date = $response->StartTime;
                }

                $SkiExportModel                     = new \App\Models\SkiExportModel;
                $SkiExportModel->fk_carpark_id      = $carpark_no;
                $SkiExportModel->client_id          = $client_id;
                $SkiExportModel->pcp_client_id      = $pcp_client_id;
                $SkiExportModel->reg                = $response->TicketNo;
                $SkiExportModel->ski_response       = $main_response;
                $SkiExportModel->transaction_type   = $transaction_type;
                $SkiExportModel->transaction_date   = $transaction_date;
                $SkiExportModel->save();
            }
        }
        catch(Exception $ex)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $ex->getMessage());
        }
    }

    function uploadMainCityFile()
    {
        $this->ViewData['pageTitle']        = 'Upload Main City File';/*
        $this->ViewData['form']['submit']   = 'Submit';
        $this->ViewData['form']['cancel']   = str_plural($this->ModelPath);
        $this->ViewData['form']['link']     = request()->url();*/

        return view($this->ViewFolder.'upload-main-city-file', $this->ViewData);
    }

    function updateMainCustomerCity(Request $request)
    {
        //dd($_FILES);
        try
        {
            $file = $_FILES['main_city_file']['tmp_name'];
            $file = Storage::disk('public')->url($file);
            $extention  = \PhpOffice\PhpSpreadsheet\IOFactory::identify($file);
            $reader     = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($extention);
            $reader->setReadDataOnly(TRUE);
            $spreadsheet = $reader->load($file);
            $sheet_data = $spreadsheet->getActiveSheet()->toArray();
            dd($sheet_data);
            for($i=1;$i<=count($sheet_data);$i++)
            {
                //dd($sheet_data[$i]);
                //$corporate_id = CorporatesModel::where('client_no',$sheet_data[$i][0])->value('id');
                $customer_id = CustomersModel::where('client_no',$sheet_data[$i][0])->value('id');
                //dd($customer_id);
                $carpark_id = CarParksModel::where('name',$sheet_data[$i][1])->value('id');
                CustomerMinParkModel::where('fk_customer_id',$customer_id)
                                    ->where('billing_group_id',86)
                                    ->where('carpark_id',$carpark_id)
                                    ->where('fk_corporate_id',2)
                                    ->whereDate('created_at','2022-08-17')
                                    ->update([  
                                        'min_park'=>$sheet_data[$i][4],
                                        'remaining_visit_charges'=>$sheet_data[$i][6]
                                    ]);

                /*CustomerInvoiceModel::where('fk_customer_id',$customer_id)
                                    ->where('billing_group_id',86)
                                    ->update([
                                        'remaining_visits'=>$sheet_data[$i][9],
                                        'remaining_visit_charges'=>$sheet_data[$i][10]
                                    ]);*/

                /*$min_park = CustomerMinParkModel::where('fk_corporate_id',$corporate_id)
                                    ->where('billing_group_id',86)
                                    ->sum('min_park');

                $charges = CustomerMinParkModel::where('fk_corporate_id',$corporate_id)
                                    ->where('billing_group_id',86)
                                    ->sum('remaining_visit_charges');

                CustomerInvoiceModel::where('fk_corporate_id',$corporate_id)
                                    ->where('billing_group_id',86)
                                    ->update([
                                        'remaining_visits'=>$min_park,
                                        'remaining_visit_charges'=>$charges
                                    ]);*/
                /*$min_park = CustomerMinParkModel::where('fk_customer_id',$customer_id)
                                    ->where('billing_group_id',86)
                                    ->whereDate('created_at','2022-08-16')
                                    ->sum('min_park');

                $charges = CustomerMinParkModel::where('fk_customer_id',$customer_id)
                                    ->where('billing_group_id',86)
                                    ->whereDate('created_at','2022-08-16')
                                    ->sum('remaining_visit_charges');*/

                /*CustomerInvoiceModel::where('fk_customer_id',$customer_id)
                                    ->where('billing_group_id',86)
                                    ->whereDate('created_at','2022-08-16')
                                    ->update([
                                        'remaining_visit_charges'=>$sheet_data[$i][6]
                                    ]);*/
            }

            dd('done');
        }catch(Exception $ex)
        {
           Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $ex->getMessage());
        }
    }

    function makeMaster(Request $request)
    {
        //dd($request->all());
        $customer_id = ClientAccountLinkModel::where('child_pcp_client_id',$request->new_master_pcp_id)->value('child_customer_id');
        ClientAccountLinkModel::where('master_customer_id',$request->id)
                                ->update([
                                    'master_customer_id'=>$customer_id,
                                    'master_pcp_client_id'=>$request->new_master_pcp_id
                                ]);
        return "true";
    }

    public function addAlternateReg(Request $request)
    {
        $response = array();

        CustomerVehicalRegModel::where('id',$request->id)
                                ->update([
                                    'alternate_reg_no' => trim(strtoupper($request->reg_no_1).','.strtoupper($request->reg_no_2).','.strtoupper($request->reg_no_3))
                                ]);

        $response['status'] = true;
        $response['message'] = "Alternate Reg Added Successfully";
        return $response;
    }

    public function test()
    {
        $customers = CustomerMinParkModel::where('billing_group_id','>','90')->get();
        foreach ($customers as $key => $customer) 
        {
            CustomerInvoiceMinParkModel::where('fk_customer_id',$customer['fk_customer_id'])
                                        ->where('billing_group_id',$customer['billing_group_id'])
                                        ->where('fk_corporate_id',$customer['fk_corporate_id'])
                                        ->update([
                                            'carpark_id'=>$customer['min_carpark_id'],
                                            'subscription_id'=>$customer['min_subscription_id']
                                        ]);
        }

        dd("zala. bagh");
        //dd(strtotime(now()));
        /*$version_number = 0;
        $id = 1001;//Assigned by NewPark as Secret Key
        $time = now();
        $string = 'abcdefghijkl';
        //$msg = "0,1001,1663068797,abcdefghijkl";
        dd(base64_encode(hash_hmac("sha256","$msg","dwmfttUmaU7bAugGTRnv5",true)));*/
        //$credentials = config('newpark')[$endpoint];
        /*$credentials =[

                'bentallcentre' => [
                    'version' => 0,
                    'id' => '1001',
                    'key' => 'dwmfttUmaU7bAugGTRnv5'
                ]
            ];
        $version = 0;

        $id = 1001;
        
        $key = 'dwmfttUmaU7bAugGTRnv5';

        $timestamp = time() + (24*60*60); 

        $nonce = base64_encode(random_bytes(12));

        $payload = $version.','.$id.','.$timestamp.','.$nonce;

        $signature = base64_encode(hash_hmac('sha256', $payload, $key, true));

        $authToken = $payload.','.$signature;

        dd($authToken);*/

        /* update corporate carpark and subscription in customer_invoice_min_park*/

        $customers = CustomerInvoiceMinParkModel::whereNotNull('fk_corporate_id')
                                                ->where('billing_group_id','>',86)
                                                ->get();

        //dd($customers);

        foreach ($customers as $key => $customer) 
        {
            $carpark_id = CustomerMinParkModel::where('billing_group_id',$customer['billing_group_id'])
                                                ->where('fk_customer_id',$customer['fk_customer_id'])
                                                ->value('min_carpark_id');

            $sub_id = CustomerMinParkModel::where('billing_group_id',$customer['billing_group_id'])
                                                ->where('fk_customer_id',$customer['fk_customer_id'])
                                                ->value('min_subscription_id');

            CustomerInvoiceMinParkModel::whereNotNull('fk_corporate_id')
                                        ->where('billing_group_id',$customer['billing_group_id'])
                                        ->where('fk_customer_id',$customer['fk_customer_id'])
                                        ->update([
                                            'carpark_id'=>$carpark_id,
                                            'subscription_id'=>$sub_id
                                        ]);
        }
        dd("done corporate");

        /* Update personal invoice min park into customer invoice min park */

        $customers = CustomerInvoiceModel::whereNotNull('fk_corporate_id')
                                        ->where('generated_invoice',1)
                                        ->where('billing_group_id','>',86)
                                        ->get();

        foreach ($customers as $key => $customer) 
        {
            $carpark_id = CustomerMinParkModel::where('billing_group_id',$customer['billing_group_id'])
                                                ->where('fk_customer_id',$customer['fk_customer_id'])
                                                ->value('min_carpark_id');

            $sub_id = CustomerMinParkModel::where('billing_group_id',$customer['billing_group_id'])
                                                ->where('fk_customer_id',$customer['fk_customer_id'])
                                                ->value('min_subscription_id');

            $CustomerInvoiceMinParkModel = new CustomerInvoiceMinParkModel;
            $CustomerInvoiceMinParkModel->billing_group_id = $customer['billing_group_id'];
            $CustomerInvoiceMinParkModel->fk_customer_id = $customer['fk_customer_id'];
            $CustomerInvoiceMinParkModel->fk_corporate_id = $customer['fk_corporate_id'];
            $CustomerInvoiceMinParkModel->min_park = $customer['remaining_visits'];
            $CustomerInvoiceMinParkModel->remaining_visit_charges = $customer['remaining_visit_charges'];
            $CustomerInvoiceMinParkModel->fk_invoice_id = $customer['id'];
            $CustomerInvoiceMinParkModel->carpark_id = $carpark_id;
            $CustomerInvoiceMinParkModel->subscription_id = $sub_id;
            $CustomerInvoiceMinParkModel->save();
        }

        dd("done");

        /*$customers = CustomerMinParkModel::where('billing_group_id',86)
                                        ->whereNull('fk_corporate_id')
                                        ->whereDate('created_at','2022-08-16')
                                        ->groupBy('fk_customer_id')
                                        ->pluck('fk_customer_id')
                                        ->all();
       // dd($customers);
        try
        {
            foreach ($customers as $key => $customer) 
            {
                $min_park = CustomerMinParkModel::where('fk_customer_id',$customer)
                                        ->whereNull('fk_corporate_id')
                                        ->where('billing_group_id',86)
                                        ->sum('min_park');

                $charges = CustomerMinParkModel::where('fk_customer_id',$customer)
                                    ->whereNull('fk_corporate_id')
                                    ->where('billing_group_id',86)
                                    ->sum('remaining_visit_charges');

                CustomerInvoiceModel::where('fk_customer_id',$customer)
                                        ->where('billing_group_id',86)
                                        ->update([
                                            'remaining_visits'=>$min_park,
                                            'remaining_visit_charges'=>$charges
                                        ]);
            }
            dd("done");
        }
        catch(Exception $ex)
        {
            dd($ex);
        }*/

        /*$ids = CustomerInvoiceModel::where('billing_group_id',88)
                                    ->whereNotNull('fk_corporate_id')
                                    ->pluck('id')->all();
        foreach ($ids as $key => $id) 
        {
            $CustomerInvoiceModel = CustomerInvoiceModel::find($id);
            $client_no = CorporatesModel::where('id',$CustomerInvoiceModel->fk_corporate_id)->value('client_no');
            $CustomerInvoiceModel->invoice_no = 'P-'.$client_no.'-AUG-2022';
            $CustomerInvoiceModel->save();
        }
        dd("done");*/
    }
}
