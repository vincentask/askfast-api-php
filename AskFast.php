<?php
require_once('model/question.php');
require_once('model/answer.php');
require_once('model/event_callback.php');
require_once('model/media_property.php');
require_once('lib/OAuthClient.php');

class AskFast {

    const ASK_FAST_URL = "http://live.ask-fast.com/dialoghandler/agents/dialog";
    CONST ASK_FAST_KEYSERVER_URL = "http://live.ask-fast.com/keyserver/token";

    const QUESTION_TYPE_OPEN = "open";
    const QUESTION_TYPE_CLOSED = "closed";
    const QUESTION_TYPE_COMMENT = "comment";
    
    protected $response = null;
    protected $url = null;
    
    protected $accountId = null;
    protected $refreshToken = null;
    protected $accessToken = null;
    
    public function __construct($accountId=false, $refreshToken=false, $accessToken=false) {
        $file = str_ireplace("\\","",dirname($_SERVER['PHP_SELF']));
        $this->url = 'http://'.$_SERVER["HTTP_HOST"].$file.'/';

        $this->response = new Question();
        $this->response->question_id=1;
        
        if($accountId)
            $this->publicKey = $accountId;
            
        if($refreshToken)
            $this->refreshToken = $refreshToken;

        if($accessToken)
            $this->accessToken = $accessToken;
    }
    
    public function call($address, $url=null) {

        if($this->accessToken==null) {
            $this->accessToken = $this->getAccessToken();
        }

        if($this->accessToken==null) {
            throw new Exception("Failed to obtain accessToken. (Please check accountId and refreshToken)");
        }

        if($url==null)
            $url = $this->url;
            
        $url = $this->formatURL($url);
            
        //echo "Going to call: ".$address." with url: ".$url."<br />";
        
        $params = new stdClass;
        $params->address = $address;
        $params->url = $url;
        $params->accountId = $this->accountId;
        $params->refreshToken = $this->refreshToken;
        $params->adapterType = "broadsoft";
        
        return $this->sendJSONRPC("outboundCall", $params);
    }
    
    public function ask($ask, $type, $next=null) {

        if($ask!="") {
            if($this->response->question_text=="") {
                // If the answer start with a slash it is a file
                // and the host path should be added.
                if(strpos($ask, "/")===0) {
                    $this->response->question_text = $this->url.substr($ask,1);
                }

                // if it ends with wav it is a audio file
                // so if not we should add text://
                else if(!(substr($ask, -strlen(".wav")) == ".wav")) {
                    $this->response->question_text = "text://".$ask;
                } else {
                    $this->response->question_text = $ask;
                }
            } else {
                // if it ends with wav it is a audio file
                // so if not we should add text://
                if(!(substr($ask, -strlen(".wav")) == ".wav")) {
                    $this->response->question_text.=$ask;
                }
            }
        }
        $this->response->type=$type;
        
        if($next!=null)
            $this->response->addAnswer(new Answer(1, null, $this->url.$next));
    }
    
    public function addAnswer($answer, $url) {
        
        // If the answer start with a slash it is a file
        // and the host path should be added.
        if(strpos($answer, "/")===0) {
            $answer = $this->url.substr($answer,1);
        }
        
        // if it ends with wav it is a audio file
        // so if not we should add text://
        if(!(substr($answer, -strlen(".wav")) == ".wav") && $answer!="") {
            $answer = "text://".$answer;
        }
        
        if($this->response->type!=self::QUESTION_TYPE_CLOSED)
            throw new Exception("Adding question can only be done to closed questions");
        $this->response->addAnswer(new Answer(count($this->response->answers)+1, $answer, $this->url.$url));
    }
    
    public function say($say, $next=null) {

        if($say!="") {
            if($this->response->question_text=="") {
                // If the answer start with a slash it is a file
                // and the host path should be added.
                if(strpos($say, "/")===0) {
                    $this->response->question_text = $this->url.substr($say,1);
                }

                // if it ends with wav it is a audio file
                // so if not we should add text://
                else if(!(substr($say, -strlen(".wav")) == ".wav")) {
                    $this->response->question_text = "text://".$say;
                } else {
                    $this->response->question_text = $say;
                }
            } else {
                // if it ends with wav it is a audio file
                // so if not we should add text://
                if(!(substr($say, -strlen(".wav")) == ".wav")) {
                    $this->response->question_text.=$say;
                }
            }
        }
        
        $this->response->type=self::QUESTION_TYPE_COMMENT;
        if($next!=null)
            $this->response->addAnswer(new Answer(1, null, $this->url.$next));
    }
    
    public function redirect($to, $say=null, $next=null) {
        
        $this->response->type="referral";
        $this->response->url=$to;
        
        if($say!=null) {
            // If the answer start with a slash it is a file
            // and the host path should be added.
            if(strpos($say, "/")===0) {
                $this->response->question_text = $this->url.substr($say,1);
            }
            
            // if it ends with wav it is a audio file
            // so if not we should add text://
            else if(!(substr($say, -strlen(".wav")) == ".wav")) {
                $this->response->question_text = "text://".$say;
            } else {
                $this->response->question_text = $say;
            }
        }
        
        if($next!=null)
            $this->response->addAnswer(new Answer(1, null, $this->url.$next));
    }
    
    public function hangup() {
         $this->response = new stdClass;
    }
    
    public function finish() { 
        echo $this->unescapeJSON($this->response->toJSON());
    }

    public function addEvent($event, $callback) {
        $this->response->addEventCallback(new EventCallback($event, $callback));
    }

    public function addProperty($type, $key, $value) {
        $property = new MediaPropery($type);
        $property->addProperty($key, $value);
        $this->response->addMediaProperty($property);
    }

    protected function getAccessToken() {
        if($this->refreshToken==null)
            throw new Exception("No refreshToken was set!");

        if($this->accountId==null)
            throw new Exception("No accountId was set!");

        $client = new OAuthClient(ASK_FAST_KEYSERVER_URL, $this->accountId, $this->refreshToken);
        return $client->getAccessToken();
    }

    protected function unescapeJSON($json) {
        return str_replace(array("\\", "\"{", "}\""), array("", "{", "}"), $json);
    }
        
    protected function formatURL($url) {
        
        // if it starts with http it is a full url
        if(strpos($url, "http")===0 || strpos($url, "https")===0)
            return $url;
            
        if($this->url!=null)
            return $this->url . $url;
    }
    
    protected function sendJSONRPC($method, $params) {
        
        $url = ASK_FAST_URL;
        $req = new stdClass;
        $req->id = -1;
        $req->method = $method;
        $req->params = $params;
        
        $json = json_encode($req);
        
        //echo "Going to send: ".$json."<br />";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
         
        $resp = curl_exec($ch);
        curl_close($ch);
        
        $response = json_decode($resp);
        
        return $response;
    }
}
?>
