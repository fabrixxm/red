<?php

require_once('include/security.php');
require_once('include/bbcode.php');
require_once('include/items.php');


function tagger_content(&$a) {

	if(! local_user() && ! remote_user()) {
		return;
	}

	$term = notags(trim($_GET['term']));
	// no commas allowed
	$term = str_replace(array(',',' '),array('','_'),$term);

	if(! $term)
		return;

	$item_id = ((argc() > 1) ? notags(trim(argv(1))) : 0);

	logger('tagger: tag ' . $term . ' item ' . $item_id);


	$r = q("SELECT * FROM `item` WHERE `id` = '%s' LIMIT 1",
		dbesc($item_id)
	);

	if(! $item_id || (! count($r))) {
		logger('tagger: no item ' . $item_id);
		return;
	}

	$item = $r[0];

	$owner_uid = $item['uid'];

	$r = q("select `nickname`,`blocktags` from user where uid = %d limit 1",
		intval($owner_uid)
	);
	if(count($r)) {
		$owner_nick = $r[0]['nickname'];
		$blocktags = $r[0]['blocktags'];
	}

	if(local_user() != $owner_uid)
		return;

	$r = q("select * from contact where self = 1 and uid = %d limit 1",
		intval(local_user())
	);
	if(count($r))
			$contact = $r[0];
	else {
		logger('tagger: no contact_id');
		return;
	}

	$mid = item_message_id();
	$xterm = xmlify($term);
	$post_type = (($item['resource_id']) ? t('photo') : t('status'));
	$targettype = (($item['resource_id']) ? ACTIVITY_OBJ_PHOTO : ACTIVITY_OBJ_NOTE ); 

	$link = xmlify('<link rel="alternate" type="text/html" href="' 
		. $a->get_baseurl() . '/display/' . $owner['nickname'] . '/' . $item['id'] . '" />' . "\n") ;

	$body = xmlify($item['body']);

	$target = <<< EOT
	<target>
		<type>$targettype</type>
		<local>1</local>
		<id>{$item['mid']}</id>
		<link>$link</link>
		<title></title>
		<content>$body</content>
	</target>
EOT;

	$tagid = $a->get_baseurl() . '/search?tag=' . $term;
	$objtype = ACTIVITY_OBJ_TAGTERM;

	$obj = <<< EOT
	<object>
		<type>$objtype</type>
		<local>1</local>
		<id>$tagid</id>
		<link>$tagid</link>
		<title>$xterm</title>
		<content>$xterm</content>
	</object>
EOT;

	$bodyverb = t('%1$s tagged %2$s\'s %3$s with %4$s');

	if(! isset($bodyverb))
			return; 

	$termlink = html_entity_decode('&#x2317;') . '[zrl=' . $a->get_baseurl() . '/search?tag=' . urlencode($term) . ']'. $term . '[/zrl]';

	$arr = array();

	$arr['mid'] = $mid;
	$arr['uid'] = $owner_uid;
	$arr['contact-id'] = $contact['id'];
	$arr['type'] = 'activity';
	$arr['wall'] = $item['wall'];
	$arr['gravity'] = GRAVITY_COMMENT;
	$arr['parent'] = $item['id'];
	$arr['parent_mid'] = $item['mid'];
	$arr['owner-name'] = $item['author-name'];
	$arr['owner-link'] = $item['author-link'];
	$arr['owner-avatar'] = $item['author-avatar'];
	$arr['author-name'] = $contact['name'];
	$arr['author-link'] = $contact['url'];
	$arr['author-avatar'] = $contact['thumb'];
	
	$ulink = '[zrl=' . $contact['url'] . ']' . $contact['name'] . '[/zrl]';
	$alink = '[zrl=' . $item['author-link'] . ']' . $item['author-name'] . '[/zrl]';
	$plink = '[zrl=' . $item['plink'] . ']' . $post_type . '[/zrl]';
	$arr['body'] =  sprintf( $bodyverb, $ulink, $alink, $plink, $termlink );

	$arr['verb'] = ACTIVITY_TAG;
	$arr['tgt_type'] = $targettype;
	$arr['target'] = $target;
	$arr['obj_type'] = $objtype;
	$arr['object'] = $obj;
	$arr['private'] = $item['private'];
	$arr['allow_cid'] = $item['allow_cid'];
	$arr['allow_gid'] = $item['allow_gid'];
	$arr['deny_cid'] = $item['deny_cid'];
	$arr['deny_gid'] = $item['deny_gid'];
	$arr['visible'] = 1;
	$arr['unseen'] = 1;
	$arr['origin'] = 1;

	$post_id = item_store($arr);	

	q("UPDATE `item` set plink = '%s' where id = %d limit 1",
		dbesc($a->get_baseurl() . '/display/' . $owner_nick . '/' . $post_id),
		intval($post_id)
	);
		

	if(! $item['visible']) {
		$r = q("UPDATE `item` SET `visible` = 1 WHERE `id` = %d AND `uid` = %d LIMIT 1",
			intval($item['id']),
			intval($owner_uid)
		);
	}			

	if((! $blocktags) && (! stristr($item['tag'], ']' . $term . '[' ))) {
		q("update item set tag = '%s' where id = %d limit 1",
			dbesc($item['tag'] . (strlen($item['tag']) ? ',' : '') . '#[zrl=' . $a->get_baseurl() . '/search?tag=' . $term . ']'. $term . '[/zrl]'),
			intval($item['id'])
		);
	}

	// if the original post is on this site, update it.

	$r = q("select `tag`,`id`,`uid` from item where `origin` = 1 AND `mid` = '%s' LIMIT 1",
		dbesc($item['mid'])
	);
	if(count($r)) {
		$x = q("SELECT `blocktags` FROM `user` WHERE `uid` = %d limit 1",
			intval($r[0]['uid'])
		);
		if(count($x) && !$x[0]['blocktags'] && (! stristr($r[0]['tag'], ']' . $term . '['))) {
			q("update item set tag = '%s' where id = %d limit 1",
				dbesc($r[0]['tag'] . (strlen($r[0]['tag']) ? ',' : '') . '#[zrl=' . $a->get_baseurl() . '/search?tag=' . $term . ']'. $term . '[/zrl]'),
				intval($r[0]['id'])
			);
		}

	}
		

	$arr['id'] = $post_id;

	call_hooks('post_local_end', $arr);

	proc_run('php',"include/notifier.php","tag","$post_id");

	killme();

	return; // NOTREACHED


}