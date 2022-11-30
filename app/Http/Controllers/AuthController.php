<?php

namespace App\Http\Controllers;

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

use App\Models\UsersModel;

use App\Repositories\UserRepository\UserRepositoryInterface as UserRepository;

class AuthController extends BaseController
{
    protected $UserRepository;
    protected $UserModel;
    protected $ViewFolder;
    protected $ViewData;
    protected $ModelTitle;

    function __construct(UsersModel $UsersModel, UserRepository $UserRepository)
    {
        $this->UserRepository = $UserRepository;
        $this->UsersModel    = $UsersModel;
        $this->ModelTitle   = '';
        $this->ViewData     = [];
        $this->ViewFolder   = 'admin.auth.';
    }

    /*---------------------------------
    |   login view
    */
        public function getLogin()
        {
            try {
               // dd(DB::connection()->getPdo());
               
            } catch (\Exception $e) {
                die("Could not connect to the database.  Please check your configuration. error:" . $e );
            }

            $this->ViewData['moduleTitle'] = 'Sign In';
            return view($this->ViewFolder.'login', $this->ViewData);
        }

    /*---------------------------------
    |   login attempt
    */
   
        public function postLogin(Request $request)
        {
            $validator = Validator::make($request->all(), 
            [
                'password' => 'required',
                'email' => 'required|email',
            ]);

            if ($validator->fails()) 
            {
                return redirect('login')
                    ->withErrors($validator)
                    ->withInput();
            }

            try 
            {            
                $email      = trim($request->email);
                $password   = trim($request->password);
                $rememberMe = isset($request->remember_me) && $request->remember_me == '1' ? true : false;
                if (Auth::attempt(['email' => $email, 'password' => $password], $rememberMe)) 
                {
                    if (Auth::user()->status == 'active') 
                    {                    
                        $arrAuthUserRole = $this->UsersModel->with('Role')->find(Auth::id());
                        Session::put('authUserIsSuperAdmin',$arrAuthUserRole->Role->is_superadmin);
                        Session::put('authUserRoleName',$arrAuthUserRole->Role->role);
                        return redirect('dashboard');
                    } 
                    else 
                    {
                        Auth::logout();
                        Session::flush();

                        return redirect('login')
                            ->withErrors(Lang::get('custom.user_inactive'))
                            ->withInput();
                    }
                } 
                else 
                {
                    $userDetail = DB::table('users')->where('email', $email)->orderBy('id', 'desc')->first();
                    if ($userDetail) 
                    {
                        if ($userDetail->deleted_at == null) 
                        {
                            return redirect('login')
                                ->withErrors(Lang::get('auth.failed'))
                                ->withInput();
                        } 
                        else 
                        {
                            return redirect('login')
                                ->withErrors(Lang::get('custom.user_deleted'))
                                ->withInput();
                        }
                    } 
                    else 
                    {
                        return redirect('login')
                            ->withErrors(Lang::get('auth.failed'))
                            ->withInput();
                    }
                }
            } 
            catch (\Exception $exception) 
            {
                Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
                return redirect('login')
                    ->withErrors(Lang::get('custom.something_wrong'))
                    ->withInput();
            }
        }

    /*---------------------------------
    |   logout
    */
        public function logout()
        {
            Auth::logout();
            Session::flush();
            return redirect('login');
        }

    /*---------------------------------
    |   forget password view
    */

        public function getForgetPassword()
        {
            $this->ViewData['moduleTitle'] = 'Forget Password';
            return view($this->ViewFolder.'forget-password', $this->ViewData);
        }
       
    /*---------------------------------
    |   forget password attempt
    */
        public function forgetPassword(Request $request)
        {
            $validator = Validator::make($request->all(), 
            [
                'email' => 'required|email',
            ]);

            if ($validator->fails()) 
            {
                return redirect('forget-password')
                    ->withErrors($validator)
                    ->withInput();
            }

            try 
            {

                $response = $this->UserRepository->forgetPassword($request->email);

                if ($response['status']) 
                {
                    return redirect('login')
                        ->with(['success' => $response['message']]);
                } 
                else
                {
                    return redirect('forget-password')
                        ->withErrors($response['message'])
                        ->withInput();
                }

            } 
            catch (\Exception $exception) 
            {
                Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
                return redirect('forget-password')
                    ->withErrors(Lang::get('custom.something_wrong'))
                    ->withInput();
            }
        }

    /*---------------------------------
    |   check existing email 
    */    
        public function checkEmailExist(Request $request)
        {
            $validator = Validator::make($request->all(), 
            [
                'email' => 'required|email',
            ]);

            if ($validator->fails()) 
            {
                return 'false';
            }

            try 
            {
                $checkDuplicate = isset($request->duplicate) ? 1 : 0;
                $userId = isset($request->userId) ? $request->userId : 0;
                $isExist = $this->UserRepository->checkUserExist($request->email, '', $userId);

                if ($checkDuplicate) 
                {
                    return $isExist ? 'false' : 'true';
                } 
                else 
                {
                    return $isExist ? 'true' : 'false';
                }

            } 
            catch (\Exception $exception) 
            {
                Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
                return 'false';
            }
        }

    /*---------------------------------
    |   reset password view
    */ 
        public function getResetPassword($resetKey)
        {
            $resetKey = trim($resetKey);

            $this->ViewData['moduleTitle'] = 'Reset Password';
            $this->ViewData['resetKey'] = $resetKey;
            
            try 
            {

                if (isset($resetKey)) 
                {
                    $isExist = $this->UserRepository->checkUserExist('', $resetKey);


                    if ($isExist) 
                    {
                        return view($this->ViewFolder.'reset-password', $this->ViewData);
                    } 
                    else 
                    {
                        return redirect('login')
                            ->withErrors(Lang::get('passwords.token'))
                            ->withInput();
                    }
                } 
                else 
                {
                    return redirect('login')
                        ->withErrors(Lang::get('custom.something_wrong'))
                        ->withInput();
                }

            } catch (\Exception $exception) {
                Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
                return redirect('login')
                    ->withErrors(Lang::get('custom.something_wrong'))
                    ->withInput();
            }
        }

    /*---------------------------------
    |   reset password attempt
    */ 
        public function resetPassword($resetKey, Request $request)
        {
            $validator = Validator::make($request->all(), [
                'password' => 'required|confirmed',
            ]);

            if ($validator->fails()) 
            {
                return redirect('password-reset/' . $resetKey)
                    ->withErrors($validator)
                    ->withInput();
            }

            try 
            {
                $status = $this->UserRepository->resetPassword($resetKey, $request->password);

                if ($status) 
                {
                    return redirect('login')
                        ->with(['success' => Lang::get('passwords.reset')]);

                } 
                else 
                {
                    return redirect('password-reset/' . $resetKey)
                        ->withErrors(Lang::get('custom.something_wrong'))
                        ->withInput();
                }

            } 
            catch (\Exception $exception) 
            {
                Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
                
                return redirect('password-reset/' . $resetKey)
                    ->withErrors(Lang::get('custom.something_wrong'))
                    ->withInput();
            }
        }

    /*---------------------------------
    |   reset password view
    */ 
        public function getChangePassword()
        {
            $this->ViewData['pageTitle'] = 'Change Password';
            return view($this->ViewFolder.'change-password', $this->ViewData);
        }

    /*---------------------------------
    |   reset password attempt
    */ 
        public function changePassword(Request $request)
        {
            $response = array();
            $validator = Validator::make($request->all(), 
            [
                'old_password' => 'required',
                'password' => 'required|confirmed',
            ]);

            if ($validator->fails()) 
            {
                $response['status'] = false;
                $response['message'] = Lang::get('custom.something_wrong');
                return Response::json($response);
            }

            try 
            {
                $oldPassword = $request->old_password;
                $password = $request->password;
                $response = $this->UserRepository->changePassword(Auth::id(), $oldPassword, $password);
            } 
            catch (\Exception $exception) 
            {
                Log::error(__CLASS__ . "::" . __METHOD__ . ' : ' . $exception->getMessage());
                $response = array();
                $response['status'] = false;
                $response['message'] = Lang::get('custom.something_wrong');
            }
            return Response::json($response);
        }
}
