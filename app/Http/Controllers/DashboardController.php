<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model as GraphModel;

class DashboardController extends Controller
{
    protected $ViewFolder;
    protected $ViewData;
    protected $ModelTitle;
    protected $ModelAction;

    public function __construct()
    {
        $this->ViewFolder = 'admin.'; 
    }

    /*---------------------------------
    |   dashboard view
    */
        public function index()
        {

            if (!Session::has('globalState')) 
            {
                Session::put('globalState', 'all');
            }

            $data['pageTitle'] = 'Dashboard';

            return view($this->ViewFolder.'dashboard', $data);
        }

        public function createEmail($emailBody)
        {
            $subject = now();
            
            $message = new GraphModel\Message();

            // subject
            $message->setSubject($subject);

            // body
            $body = new GraphModel\ItemBody();
            $body->setContent($emailBody);
            $message->setBody($body);
            
            // sender
            $emailAddress = new GraphModel\EmailAddress();
            $emailAddress->setAddress(config('constants.email'));

            // reciever
            $recipient = new GraphModel\Recipient();
            $recipient->setEmailAddress($emailAddress);
            
            $message->setToRecipients(array($recipient));

            return $message;
        }
}
