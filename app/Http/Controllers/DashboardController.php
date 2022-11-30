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
            // dump('mail testing');
            
            // $graph = new Graph();
            // $tenantId = 'accounts@premiumcarparks.co.uk';
            // $graph->setAccessToken(getMsGraphToken());

            // $message = $this->createEmail("Sent from the SendMail test - shesh");
            // $body = array("message" => $message);
            
            // $done = $graph->createRequest("POST", "/users/".$tenantId."/sendmail")
            //             ->attachBody($body)
            //             ->execute();

            // dd('pass', $done);

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
            $emailAddress->setAddress('sheshkumarprjpt@gmail.com');

            // reciever
            $recipient = new GraphModel\Recipient();
            $recipient->setEmailAddress($emailAddress);
            
            $message->setToRecipients(array($recipient));

            return $message;
        }
}
