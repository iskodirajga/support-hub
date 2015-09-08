<?php

class shub_ucm_message extends SupportHub_message{

    protected $network = 'ucm';

	public function load_by_network_key($network_key, $ticket, $type, $debug = false){

		switch($type){
			case 'ticket':
				$existing = shub_get_single('shub_message', 'network_key', $network_key);
				if($existing){
					// load it up.
					$this->load($existing['shub_message_id']);
				}
				if($ticket && isset($ticket['ticket_id']) && $ticket['ticket_id'] == $network_key){
                    // get the messages from the API
                    $api = $this->account->get_api();
                    $api_result = $api->api('ticket','message',array('ticket_ids'=>$network_key));
                    if($api_result && isset($api_result['tickets'][$network_key]) && count($api_result['tickets'][$network_key])) {
                        //print_r($api_result);
						$all_comments = $api_result['tickets'][$network_key];
						$comments = array();
						foreach($all_comments as $comment_id => $comment){
							if( (isset($comment['cache']) && $comment['cache'] == 'autoreply') || (isset($comment['message_type_id']) && $comment['message_type_id'] == 3)) {
                                // this is an auto reply, don't bother importing it into the system here
                            }else{
                                $comment['id'] = $comment['ticket_message_id'];
                                $comment['shub_user_id'] = $this->account->get_api_user_to_id($comment['user']);
                                $comment['timestamp'] = $comment['message_time'];
								$comments[] = $comment;
							}
						}
                        if (!$existing) {
                            $this->create_new();
                        }
                        $this->update('shub_account_id', $this->account->get('shub_account_id'));
                        $this->update('shub_item_id', $this->item->get('shub_item_id'));

						// create/update a user entry for this comments.
						$shub_user_id = $this->account->get_api_user_to_id($ticket['user']);
						$this->update('shub_user_id', $shub_user_id);

                        $this->update('title', $ticket['subject']);
                        // latest comment goes in summary
                        $this->update('summary', $comments[count($comments)-1]['content']);
                        $this->update('last_active', $ticket['last_message_timestamp']);
                        $this->update('shub_type', $type);
                        $this->update('shub_data', $ticket);
                        $this->update('shub_link', $ticket['url']);
                        $this->update('network_key', $network_key);
                        if($this->get('shub_status')!=_shub_MESSAGE_STATUS_HIDDEN){
                            // we have to decide if we're updating the message status from answered to unanswered.
                            // if this message status is already answered and the existing comment count matches the new comment count then we don't update the status
                            // this is because we insert a placeholder "comment" into the db while the API does the push, and when we read again a few minutes later it overwrites this placeholder comment, so really it's not a new comment coming in just the one we posted through the API that takes a while to come back through.
                            if($this->get('shub_status') == _shub_MESSAGE_STATUS_ANSWERED && count($comments) == count($this->get_comments())){
                                // don't do anything
                            }else{
                                // comment count is different
                                $this->update('shub_status', _shub_MESSAGE_STATUS_UNANSWERED);
                            }
                        }
                        $this->update('comments', $comments);

                        // add the extra fields from UCM into the ticket.
                        if(!empty($ticket['extra']) && is_array($ticket['extra'])){
                            foreach($ticket['extra'] as $extra_key => $extra_val){
                                $extra_val = trim($extra_val);
                                if(strlen($extra_val)) {
                                    // add this one into the system.
                                    $ExtraField = new SupportHubExtra();
                                    if (!$ExtraField->load_by('extra_name', $extra_key)) {
                                        $ExtraField->create_new();
                                        $ExtraField->update('extra_name', $extra_key);
                                    }
                                    $ExtraField->save_and_link(array(
                                        'extra_value' => $extra_val
                                    ),'ucm',$this->account->get('shub_account_id'),$this->get('shub_message_id'),$shub_user_id);
                                }

                            }
                        }
                    }

					return $this->get('shub_message_id');
				}
				break;

		}
		return false;
	}


    public function send_queued_comment_reply($envato_message_comment_id, $shub_outbox, $debug = false){
        $comments = $this->get_comments();
        if(isset($comments[$envato_message_comment_id]) && !empty($comments[$envato_message_comment_id]['message_text'])){
            $api = $this->account->get_api();
            $outbox_data = $shub_outbox->get('message_data');
            if($outbox_data && isset($outbox_data['extra']) && is_array($outbox_data['extra'])){
                $extra_data = $outbox_data['extra'];
            }else{
                $extra_data = array();
            }
            $api_result = $api->api('ticket','reply',array(
                'ticket_id'=>$this->get('network_key'),
                'message'=>$comments[$envato_message_comment_id]['message_text'],
                'extra_data'=>$extra_data,
            ));
            if ($api_result && !empty($api_result['ticket_message_id'])) {
                if($debug){
                    echo 'UCM API Result:';
                    print_r($api_result);
                }
                // add a placeholder in the comments table, next time the cron runs it should pick this up and fill in all the details correctly from the API
                shub_update_insert('shub_message_comment_id', $envato_message_comment_id, 'shub_message_comment', array(
                    'network_key' => $api_result['ticket_message_id'],
                    'time' => time(),
                ));
                return true;
            } else {
                echo "Failed to send comment, check debug log. ".var_export($api_result,true);
                return false;
            }
        }
        echo 'No comment found to send.';
        return false;
    }


    public function reply_actions(){
        $user_data = $this->account->get('account_data');
        if(isset($user_data['reply_options']) && is_array($user_data['reply_options'])){
            foreach($user_data['reply_options'] as $reply_option){
                if(isset($reply_option['title'])){
                    echo '<div>';
                    echo '<label for="">'.htmlspecialchars($reply_option['title']).'</label>';
                    if(isset($reply_option['field']) && is_array($reply_option['field'])){
                        $reply_option['field']['name'] = 'extra-'.$reply_option['field']['name'];
                        $reply_option['field']['data'] = array(
                            'reply' => 'yes'
                        );
                        shub_module_form::generate_form_element($reply_option['field']);
                    }
                    echo '</div>';
                }
            }
        }
    }

    public function get_link() {
        $item = $this->get('item');
        $url = '#';
        if($this->account){
            $bits = @parse_url($this->account->get('ucm_api_url'));
            if($bits && !empty($bits['path'])){
                $url = $bits['scheme'].'://'.$bits['host'].str_replace('ext.php','',str_replace('external/m.api/h.v1','',$bits['path']));
                $url .= '?m=ticket&p=ticket_admin';
            }
            if($item){
                $url .= '&faq_product_id='.$item->get('network_key');
                $url .= '&ticket_id='.$this->get('network_key');
            }
        }
        return $url;
    }

	public function get_type_pretty() {
		$type = $this->get('type');
		switch($type){
			case 'ticket':
				return 'Support Ticket';
				break;
			default:
				return ucwords($type);
		}
	}

	public function get_from() {
		if($this->shub_message_id){
			$from = array();
			if($this->get('shub_user_id')){
				$from[$this->get('shub_user_id')] = new SupportHubUser_ucm($this->get('shub_user_id'));
			}
			$messages = $this->get_comments();
			foreach($messages as $message){
				if($message['shub_user_id'] && !isset($from[$message['shub_user_id']])){
					$from[$message['shub_user_id']] = new SupportHubUser_ucm($message['shub_user_id']);
				}
			}
			return $from;
		}
		return array();
	}
    public function message_sidebar_data(){

        // find if there is a product here
        $shub_product_id = $this->get_product_id();
        $product_data = array();
        $item_data = array();
        $item = $this->get('item');
        if(!$shub_product_id && $item){
            $shub_product_id = $item->get('shub_product_id');
            $item_data = $item->get('item_data');
            if(!is_array($item_data))$item_data = array();
        }
        if($shub_product_id) {
            $shub_product = new SupportHubProduct();
            $shub_product->load( $shub_product_id );
            $product_data = $shub_product->get( 'product_data' );
        }
        //echo SupportHub::getInstance()->message_managers['ucm']->get_friendly_icon();
        ?>
        <img src="<?php echo plugins_url('extensions/ucm/logo.png', _DTBAKER_SUPPORT_HUB_CORE_FILE_);?>" class="shub_message_account_icon">
        <?php
        if($shub_product_id && !empty($product_data['image'])) {
            ?>
            <img src="<?php echo $product_data['image'];?>" class="shub_message_account_icon">
            <?php
        }
        ?>
        <br/>
        <strong><?php _e('Subject:');?></strong> <?php echo htmlspecialchars( $this->get('title') );?> <br/>
        <strong><?php _e('Account:');?></strong> <a href="<?php echo $this->get_link(); ?>" target="_blank"><?php echo htmlspecialchars( $this->get('account') ? $this->get('account')->get( 'account_name' ) : 'N/A' ); ?></a> <br/>
        <strong><?php _e('Time:');?></strong> <?php echo shub_print_date( $this->get('last_active'), true ); ?>  <br/>

        <?php
        if($item_data){
            ?>
            <strong><?php _e('Item:');?></strong>
            <a href="<?php echo isset( $item_data['url'] ) ? $item_data['url'] : $this->get_link(); ?>"
               target="_blank"><?php
                echo htmlspecialchars( $item_data['item'] ); ?></a>
            <br/>
            <?php
        }

        /*$data = $this->get('shub_data');
        if(!empty($data['buyer_and_author']) && $data['buyer_and_author'] && $data['buyer_and_author'] !== 'false'){
            // hmm - this doesn't seem to be a "purchased" flag.
            ?>
            <strong>PURCHASED</strong><br/>
            <?php
        }*/
    }

    public function get_user_hints($user_hints = array()){
        $user_hints['shub_user_id'][] = $this->get('shub_user_id');
        $comments         = $this->get_comments();
        $first_comment = current($comments);
        if(isset($first_comment['shub_user_id']) && $first_comment['shub_user_id']){
            $user_hints['shub_user_id'][] = $first_comment['shub_user_id'];
        }
        return $user_hints;
    }

    public function get_user($shub_user_id){
        return new SupportHubUser_ucm($shub_user_id);
    }
    public function get_reply_user(){
        return new SupportHubUser_ucm($this->account->get('shub_user_id'));
    }

}