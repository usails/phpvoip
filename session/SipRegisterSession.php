<?php
class SipRegisterSession extends SipSession
{
	public $username;
	public $password;
	public $domain;

	function __construct($username, $password, $remote_ip, $remote_port){
		parent::__construct();

		$ps = explode('@', $username);
		if(count($ps) > 1){
			$username = $ps[0];
			$domain = $ps[1];
		}else{
			$domain = $remote_ip;
		}
		
		$this->role = SIP::REGISTER;
		$this->state = SIP::REGISTERING;
		$this->timers = self::$reg_timers;
		
		$this->remote_ip = $remote_ip;
		$this->remote_port = $remote_port;
		$this->username = $username;
		$this->password = $password;
		$this->domain = $domain;

		$this->uri = "sip:{$this->domain}";
		$this->from = "\"{$this->username}\" <sip:{$this->username}@{$this->domain}>";
		$this->to = $this->from;
		$this->contact = $this->from;
		
		$this->call_id = SIP::new_call_id();
		$this->branch = SIP::new_branch();
		$this->from_tag = SIP::new_tag();
		$this->cseq = mt_rand(1, 10000);
	}

	function incoming($msg){
		if($this->state == SIP::REGISTERING || $this->state == SIP::AUTHING || $this->state == SIP::REG_REFRESH){
			if($msg->is_response()){
				if($msg->code == 200){
					if($this->state == SIP::REG_REFRESH){
						Logger::debug("refreshed");
					}else{
						Logger::debug("registered");
					}
					$this->to_tag = $msg->to_tag;
					$this->state = SIP::REGISTERED;

					// registration refresh
					$expires = min($this->expires, max($this->expires, $expires)) - 5;
					Logger::debug("expires: $expires");
					$this->timers = self::$reg_timers;
					$this->timers[0] = $expires;
				}else if($msg->code == 401){
					$this->auth = $this->www_auth($msg->auth);
					$this->timers = self::$reg_timers;
					if($this->state == SIP::AUTHING){
						Logger::error("auth failed");
						$this->timers[0] = 3; // wait before retry
					}else{
						Logger::debug("auth");
						$this->state = SIP::AUTHING;
					}
				}else if($msg->code == 423){
					// 423 Interval Too Brief
					foreach($msg->headers as $v){
						if($v[0] == 'Min-Expires'){
							$this->expires = max($this->expires, intval($v[1]));
							break;
						}
					}
				}
			}
		}else if($this->state == SIP::REGISTERED){
			Logger::debug("recv, exit.");
			die();
		}
	}
	
	// 返回要发送的消息
	function outgoing(){
		$msg = null;
		if($this->state == SIP::REGISTERING || $this->state == SIP::AUTHING || $this->state == SIP::REG_REFRESH){
			$this->branch = SIP::new_branch();
			
			$msg = new SipMessage();
			$msg->method = 'REGISTER';
			$msg->expires = $this->expires;
			if($this->state == SIP::AUTHING){
				$msg->headers[] = array('Authorization', $this->auth);
			}

			$msg->username = $this->username;
			$msg->password = $this->password;
		}else if($this->state == SIP::REGISTERED){
			Logger::debug("refresh registration");
			$this->state = SIP::REG_REFRESH;
			// $this->to_tag = null; TODO:?
			$this->timers = self::$reg_timers;
		}
		return $msg;
	}

	private function www_auth($str){
		$auth = SIP::parse_www_auth($str);
		$scheme = $auth['scheme'];
		$realm = $auth['realm'];
		$nonce = $auth['nonce'];
		if($scheme == 'Digest'){
			$ha1 = md5($this->username .':'. $realm .':'. $this->password);
		    $ha2 = md5('REGISTER' .':'. $this->uri);
			if(isset($auth['qpop']) && $auth['qpop'] == 'auth'){
				//MD5(HA1:nonce:nonceCount:cnonce:qop:HA2)
			}else{
				$res = md5($ha1 .':'. $nonce .':'. $ha2);
			}
		    $ret = $scheme . ' username="'.$this->username.'", realm="'.$realm.'", nonce="'.$nonce.'", uri="'.$this->uri.'", response="'.$res.'", algorithm=MD5';
			return $ret;
		}
	}
}