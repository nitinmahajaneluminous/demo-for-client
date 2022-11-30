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
                   $srtClientID = "<a href='" . url('customer/carparkClientEdit/' . $collection['id']) . "' class='' data-id='" . $collection["id"] . "' title='Add Carpark Client id'><span class='glyphicon glyphicon-eye-open'></span></a>";
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

                if($collection['referral'] == 'new' && $collection['status']!='inactive') 
                {
                    $action .= "<a href='" . url('customers/new/referral/' . $collection['id']) . "' class='refer-user action-icon' title='Refer Credit To Customer'><span class='glyphicon glyphicon-level-up'></span></a>";
                    
                }
                
                array_push($row, $action);

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

    ##Storing function for storing customer data
    public function store(Request $request)
    {
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

        try
        {
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
 
        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return redirect(str_plural($this->ModelPath))
                ->with(['error' => Lang::get('custom.something_wrong')])
                ->withInput();
        }
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
        $this->ViewData['real_reg_html'] = $reg_html;
        $this->ViewData['alternate_regs'] = $data;
        $this->ViewData['minparkdata'] = $min_park_data;
        $this->ViewData['requested_carparks']=$this->BaseRepository->getRequestedCarparks($id,false);

        $this->ViewData['rejected_carparks']=CustomerCarparkRequestModel::leftJoin('car_parks','car_parks.id','customer_carpark_request.fk_carpark_id')
        ->where('fk_customer_id',$id)->where('customer_carpark_request.status','rejected')->get();


        $this->ViewData['actitvityLog']     = $this->BaseRepository->getCarparkActivity($id);  
        $this->ViewData['objactivecustomer']= $this->BaseRepository->getActiveCollection($id);
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

        try
        {
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
                if($request->status == 'inactive')
                {
                    $carparks = CustomerCarparkModel::join('car_parks','car_parks.id','customer_carpark.fk_carpark_id')
                                                ->where('fk_customer_id',$request->id)
                                                ->whereNotNull('facility_no')
                                                ->get(['facility_no','ski_carpark_no','from_date','expiry_date','fk_carpark_id']);

                    $client_no = CustomersModel::where('id',$id)->value('client_no');
                    foreach ($carparks as $key => $carpark) 
                    {
                        $data['APIKey'] = $this->CommonRepository->getSkiApiKey();
                        $data['FacilityNo'] = $carpark['facility_no'];
                        $data['FacilityNo'] = '550012';
                        $data['ReferenceNo'] = $client_no;
                        $this->CommonRepository->makeCurlCallToSki('DeleteIdentifier',$data,'Inactivate Customer','',0,$id);
                    }
                }
                /* pipedrive start */
                $user = CustomersModel::find($id);

                $pipedrive_id = $this->CommonRepository->userExistsInPipedriveByPCPId($user->client_no);

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

        }
        catch (\Exception $exception)
        {
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return redirect(str_plural($this->ModelPath))
                ->with(['error' => Lang::get('custom.something_wrong')])
                ->withInput();
        }
    }

    public function subupdate(Request $request, $id)
    {
        try
        {
            $response = $this->CommonRepository->subupdate($request, $id);
            /* update corporate customer count */
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
        
        try
        {
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
        }  
        catch (\Exception $exception)
        {
            //dd($exception->getMessage());
            Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
            return redirect(str_plural($this->ModelPath))
                ->with(['error' => Lang::get('custom.something_wrong')])
                ->withInput();
        }
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


        $new_request->carpark = $carpark;

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
                    /* return response in ski */
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
        try
        {
            $loginId = Auth::id();
            $response = $this->CommonRepository->createRegistrationNo($request);
            if ($response['status'])
            {
                /* add reg no to ski */
                if($response['reg_status'] == 'active' && (empty($request->effective_from) || strtotime($request->effective_from) == strtotime(date('d/m/Y'))))
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
                            $data['APIKey'] = $this->CommonRepository->getSkiApiKey();
                            $data['FacilityNo'] = $carpark['facility_no'];
                            $data['ValidCarparks'] =[$carpark['ski_carpark_no']];
                            $data['FacilityNo'] = '550012';
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
                }
                /* add reg no to ski end */
                $client_no = CustomersModel::where('id',$request->id)->value('client_no');

                $collect['id'] = $this->CommonRepository->userExistsInPipedriveByPCPId($client_no);
                if($collect['id']>0)
                {
                    $reg_numbers  = CustomerVehicalRegModel::where('fk_customer_id',$request->id)
                                                ->where('status','active')
                                                ->pluck('vehicle_registration_number')
                                                ->all();
                 
                    $collect['57b9d67acd1fb667856'] = $reg_numbers;//live

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
       
        {
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

                array_push($row, $referred_name);
                
               
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

                {
                    $activeRemove = '<a data-status="' . $collection['refer_status'] . '" transaction-type="' . $collection['credit_type'] . '" id="remove-' . $collection['id'] . '" title="Removed" data-id="' . $collection['id'] . '" class="btn btn-danger" onclick="changeStatusRemove(this)" data-status="remove">Remove</a>';  
                }
              
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
}