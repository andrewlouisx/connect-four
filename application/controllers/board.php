<?php

class Board extends CI_Controller {
	 
	function __construct() {
		// Call the Controller constructor
		parent::__construct();
		session_start();
	}

	public function _remap($method, $params = array()) {
		// enforce access control to protected functions

		if (!isset($_SESSION['user']))
			redirect('account/loginForm', 'refresh'); //Then we redirect to the index page again
		 
		return call_user_func_array(array($this, $method), $params);
	}

	//Begins the match
	function index() {
		$user = $_SESSION['user'];

		$userId = $user->id;
			
		$this->load->model('user_model');
		$this->load->model('invite_model');
		$this->load->model('match_model');

		$user = $this->user_model->get($user->login);
		$invite = $this->invite_model->get($user->invite_id);
		$match = $this->match_model->get($user->match_id);

		if ($user->user_status_id == User::WAITING) {
			$invite = $this->invite_model->get($user->invite_id);
			$otherUser = $this->user_model->getFromId($invite->user2_id);
		}
		else if ($user->user_status_id == User::PLAYING) {
			if ($match->user1_id == $user->id)
				$otherUser = $this->user_model->getFromId($match->user2_id);
			else
				$otherUser = $this->user_model->getFromId($match->user1_id);

		}

		$data['user'] = $user;

		// In the match table
		// user1 is always the other player
		// user2 is always the host player

		echo print_r($match);

		if (!isset($match)){
			$data['playerType'] = "inviter";
		}
		else{
			if ($match->user2_id == $userId){
				$data['playerType'] = "inviter";
			}
			else{
				$data['playerType'] = "invitee";
			}
		}

		$data['otherUser']=$otherUser;

		switch($user->user_status_id) {
			case User::PLAYING:
				$data['status'] = 'playing';
				break;
			case User::WAITING:
				$data['status'] = 'waiting';
				break;
		}

		$data['header'] = $this->load->view('partials/header.php', $data, true);
		$this->load->view('match/board',$data);
	}

	//changes the message for each player in the game
	function postMsg() {
		$this->load->library('form_validation');
		$this->form_validation->set_rules('msg', 'Message', 'required');
			
		if ($this->form_validation->run() == TRUE) {
			$this->load->model('user_model');
			$this->load->model('match_model');

			$user = $_SESSION['user'];
				
			$user = $this->user_model->getExclusive($user->login);
			if ($user->user_status_id != User::PLAYING) {
				$errormsg="Not in PLAYING state";
				goto error;
			}

			$match = $this->match_model->get($user->match_id);

			$msg = $this->input->post('msg');

			if ($match->user1_id == $user->id)  {
				$msg = $match->u1_msg == ''? $msg :  $match->u1_msg . "\n" . $msg;
				$this->match_model->updateMsgU1($match->id, $msg);
			}
			else {
				$msg = $match->u2_msg == ''? $msg :  $match->u2_msg . "\n" . $msg;
				$this->match_model->updateMsgU2($match->id, $msg);
			}
				
			echo json_encode(array('status'=>'success'));
				
			return;
		}

		$errormsg="Missing argument";
			
		error:
		echo json_encode(array('status'=>'failure','message'=>$errormsg));
	}

	//changes the board state
	function postBoardState() {
			
		$this->load->model('user_model');
		$this->load->model('match_model');

		$user = $_SESSION['user'];
			
		$user = $this->user_model->getExclusive($user->login);
		if ($user->user_status_id != User::PLAYING) {
			$errormsg="Not in PLAYING state";
			return;
		}

		$match = $this->match_model->get($user->match_id);

		$board_state = $this->input->post('board_state');
			
		$board_state_blob = base64_encode(serialize($board_state));
		$this->match_model->updateBoardState($match->id, $board_state_blob);

		$match_status = $board_state[43];
		$this->match_model->updateStatus($match->id,$match_status);

		echo json_encode(array('status'=>'success'));
			
		return;
	}
	//retrieves the state of the board
	function getBoardState() {

		$this->load->model('user_model');
		$this->load->model('match_model');

		$user = $_SESSION['user'];

		$user = $this->user_model->getExclusive($user->login);
		if ($user->user_status_id != User::PLAYING) {
			$errormsg="Not in PLAYING state";
			return;
		}

		$match = $this->match_model->get($user->match_id);
		$board_state = $this->match_model->getBoardState($match->id);


		// We should default this to a string of 42 zeros.
		$board_state = $board_state->board_state;

		$board_state = unserialize(base64_decode($board_state));

		echo json_encode(array('status'=>$board_state));

		return;
	}

	//retrieves the message from each player in the game
	function getMsg() {
		$this->load->model('user_model');
		$this->load->model('match_model');

		$user = $_SESSION['user'];

		$user = $this->user_model->get($user->login);
		if ($user->user_status_id != User::PLAYING) {
			$errormsg="Not in PLAYING state";
			goto error;
		}
		// start transactional mode
		$this->db->trans_begin();

		$match = $this->match_model->getExclusive($user->match_id);

		if ($match->user1_id == $user->id) {
			$msg = $match->u2_msg;
			$this->match_model->updateMsgU2($match->id,"");
		}
		else {
			$msg = $match->u1_msg;
			$this->match_model->updateMsgU1($match->id,"");
		}

		if ($this->db->trans_status() === FALSE) {
			$errormsg = "Transaction error";
			goto transactionerror;
		}
			
		// if all went well commit changes
		$this->db->trans_commit();
			
		echo json_encode(array('status'=>'success','message'=>$msg));
		return;

		transactionerror:
		$this->db->trans_rollback();

		error:
		echo json_encode(array('status'=>'failure','message'=>$errormsg));
	}

}

