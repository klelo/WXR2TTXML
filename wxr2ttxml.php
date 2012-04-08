<?php
/**
 * wxr2ttxml
 *
 * @author	team klelo
 * @link	https://github.com/klelo/WXR2TTXML
 */

if(!isset($_FILES['wxr']))
{
	header("Content-Type: text/html; charset=UTF-8");
	echo 'wxr2ttxml converter 0.1';
	?>
<form enctype="multipart/form-data" method="POST">
<input name="wxr" type="file" /><input type="submit" value="업로드" /></form>
	<?php
} else {
	if(end(explode(".", strtolower($_FILES['wxr']['name']))) != "xml")
	{
		header("Content-Type: text/html; charset=UTF-8");
		die('xml 파일만 업로드하실 수 있습니다.');
	} else{
		$input = file_get_contents($_FILES['wxr']['tmp_name']); 
	}
	
	function lerror($errno, $errstr, $errfile, $errline)
	{
		header("Content-Type: text/html; charset=UTF-8");
		die('error' . $errstr . $errline);
	}
	set_error_handler("lerror");

$data = str_replace(array("\x08", ""), "", $input); 
$data = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $data); 
$xml = new SimpleXMLElement($data);
$wp = $xml->channel;
date_default_timezone_set('Asia/Seoul');

// 블로그의 기본 정보를 텍스트큐브에 맞게 변경합니다.

$name = (string) $wp->title;
$desc = (string) $wp->description;
$wurl = (string) $wp->link;
$upload_url = preg_replace('/\//', '\\/', $wurl . '/');

// 워드프레스의 카테고리 정보를 텍스트큐브에 맞게 변경합니다. 3단 이상 사용시 오류가 발생할 수 있습니다

$nice_to_long = array();
$categories = array();
$categoriesn = array();

for ($i=0; $i < count($wp->wpcategory); $i++)
{
	$nice = (string) $wp->wpcategory[$i]->wpcategory_nicename;
	if($wp->wpcategory[$i]->wpcategory_parent != "")
	{
		$parent = (string) $wp->wpcategory[$i]->wpcategory_parent;
		if(array_key_exists($parent, $category_in))
		{
			array_push($category_in[$parent], $wp->wpcategory[$i]->wpcategory_nicename . '');
			$parent2 = $parent;
		} else
		{
			array_push($category_in[$parent2], $wp->wpcategory[$i]->wpcategory_nicename . '');
		}
	} else 
	{
		$category_in[$nice] = array();
		array_push($categories, $wp->wpcategory[$i]->wpcat_name . '');
		array_push($categoriesn, $wp->wpcategory[$i]->wpcategory_nicename . '');
	}
	$nice_to_long[$nice] = (string) $wp->wpcategory[$i]->wpcat_name;
}

foreach ($categoriesn as $category)
{
	foreach($category_in[$category] as $categoryin)
	{
		$long_to_short[$nice_to_long[$category] . '/' . $nice_to_long[$categoryin]] = $nice_to_long[$categoryin];
		$nice_to_long[$categoryin] = $nice_to_long[$category] . '/' . $nice_to_long[$categoryin];
	}
}

// 글 정보를 긁어옵니다.

$notices = array();
$posts = array();
$attaches = array();

for ($i = 0; $i < count($wp->item); $i++)
{
	$item = $wp->item[$i];

	// 게시글의 제목을 확인합니다.
	$post['title'] = (string) $item->title;
		
	// 게시글의 내용을 확인합니다.
	$post['content'] = (string) $item->contentencoded;
	
	// 첨부파일이 있는가 확인합니다.
	$post['attaches'] = array();
	if(preg_match_all("/$upload_url(.*?)\.(jpg|gif|png)/i", $post['content'], $matches))
	{
		// $matches[0] -> 긁어와야 할 url들
		// $matches[1] -> 디렉터리/파일 이름만
		// $matches[2] -> 파일 확장자
		for($j = 0; $j < count($matches[0]); $j++)
		{
			// 디렉터리/파일 이름 형태를 디렉터리+파일 이름 형태로 바꿉니다.
			$file['name'] = preg_replace('/\//', '', $matches[1][$j] . '.' . $matches[2][$j] );
			// 파일 이름이 전체 첨부파일 중에 있는가 확인해봅니다.
			if(!in_array($file['name'], $attaches))
			{
				// 파일 이름을 긁어온다.
				$ch = curl_init($matches[0][$j]);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				$file['content'] = curl_exec($ch);
				$file['content'] = base64_encode($file['content']);
				
				array_push($post['attaches'], $file);
				array_push($attaches, $file['name']);
			}
			$post['content'] = str_replace($matches[0][$j], '[##_ATTACH_PATH_##]/' . $file['name'], $post['content']);
		}
	}
	
	// 게시글의 slug를 가져옵니다
	$post['slogan'] = (string) $item->wppost_name;
	
	// 게시글의 번호를 확인합니다.
	$post['id'] = (string) $item->wppost_id;
	
	// 공개 상태를 확인합니다
	$post['status'] = (string) $item->wpstatus;
	if($post['status'] == "publish")
	{
		// 공개글
		$post['status'] = "public";
	} else 
	{
		// 비공개글
		$post['status'] = "private";
	}
	
	// 패스워드 상태를 확인합니다.
	$post['password'] = (string) $item->wppost_password;
	if($post['password'] != "")
	{
		// 보호글
		$post['status'] = "protected";
	}
	
	// 게시글 작성 일자를 워드프레스 형식에서 텍스트큐브 형식으로 바꿉니다.
	$date = new DateTime($item->wppost_date);
	$post['date'] = $date->getTimestamp();
	if(!$post['date']) $post['date'] = 0;

	if($item->wppost_type == "page")
	{
		// 공지사항에다가 둡니다.
		array_push($notices, $post);
	} else if($item->wppost_type == "post")
	{
		// 댓글 허용 유무와 트랙백 허용 유무를 확인합니다.
		if ($item->wpcomment_status == "open")
		{
			$post['comment_closed'] = "";
		} else
		{
			$post['comment_closed'] = "1";
		}
		if ($item->wpping_status == "open")
		{
			$post['trackback_closed'] = "";
		} else
		{
			$post['trackback_closed'] = "1";
		}
		
		// 태그와 카테고리를 확인합니다.
		$post['tag'] = array();
		$post['category'] = array();
		for ($j = 0; $j < count($item->category); $j++)
		{
			if ($item->category[$j]['domain'] == "post_tag")
			{
				array_push($post['tag'], $item->category[$j] . '');
			} else if ($item->category[$j]['domain'] == "category")
			{
				array_push($post['category'], $nice_to_long[$item->category[$j]['nicename'] . '']);
			}
		}
		// 텍스트큐브의 특성상 한 글에는 한 카테고리만 설정할 수 있으므로, 첫번째 카테고리만 사용하도록 설정합니다.
		$post['category'] = $post['category'][0];
		if($post['category'] == "Uncategorized") $post['category'] = ""; // uncategorized 카테고리에 들어간 글의 경우 분류 없음에 집어넣습니다.
				
		// 댓글을 가져옵니다. 2단 댓글은 워드프레스에서 지원은 하지만 텍스트큐브에 비해 엄청 복잡하고 안 쓰이므로....
		$post['comment'] = array();
		for ($j = 0; $j < count($item->wpcomment); $j++)
		{
			$reply = $item->wpcomment[$j];
			
			// 댓글 작성자 나와 임마
			$comment['commenter']['name'] = (string) $reply->wpcomment_author;
			$comment['commenter']['homepage'] = (string) $reply->wpcomment_author_url;
			$comment['commenter']['ip'] = (string) $reply->wpcomment_author_ip;
			
			// 댓글 작성 일자를 워드프레스 형식에서 텍스트큐브 형식으로 바꿉니다.
			$comment['date'] = new DateTime($reply->wpcomment_date . '');
			$comment['date'] = $date->getTimestamp();
			if(!$comment['date']) $comment['date'] = 0;
			
			// 댓글의 내용을 불러옵니다.
			$comment['content'] = (string) $reply->wpcomment_content;
			array_push($post['comment'], $comment);
		}
		
		array_push($posts, $post);
	}
}

header('Content-type: application/xml');
header('Content-Disposition: attachment; filename="WXR2TTXML.xml"');

echo '<?xml version="1.0" encoding="utf-8" ?>';
?>
<blog type="tattertools/1.1" migrational="true">
	<setting>
		<title><?php echo $name ?></title>
		<description><?php echo $desc ?></description>
	</setting>
<?php for($i = 0; $i < count($categories); $i++): ?>
	<category>
		<name><?php echo $categories[$i] ?></name>
		<priority><?php echo $i ?></priority>
<?php for($j = 0; $j < count($category_in[$categoriesn[$i]]); $j++): ?>
		<category>
			<name><?php echo $long_to_short[$nice_to_long[$category_in[$categoriesn[$i]][$j]]] ?></name>
			<priority><?php echo $j ?></priority>
		</category>
<?php endfor ?>
	</category>
<?php endfor ?>
<?php foreach($posts as $post): ?>
	<post slogan="<?php echo $post['slogan'] ?>" format="1.1" >
		<id><?php echo $post['id'] ?></id>
		<visibility><?php echo $post['status'] ?></visibility>
		<title><?php echo htmlspecialchars($post['title']) ?></title>
		<content>
			<?php echo htmlspecialchars($post['content']) ?>

		</content>
<?php if($post['status'] == "protected"): ?>
		<password><?php echo $post['password']; ?></password>
<?php endif; ?>
		<acceptComment><?php echo $post['comment_closed'] ?></acceptComment>
		<acceptTrackback><?php echo $post['trackback_closed'] ?></acceptTrackback>
		<published><?php echo $post['date'] ?></published>
		<created><?php echo $post['date'] ?></created>
		<modified><?php echo $post['date'] ?></modified>
		<category><?php echo $post['category'] ?></category>
<?php foreach($post['comment'] as $comment): ?>
		<comment>
			<commenter>
				<name><?php echo htmlspecialchars($comment['commenter']['name']) ?></name>
				<homepage><?php echo htmlspecialchars($comment['commenter']['homepage']) ?></homepage>
				<ip><?php echo $comment['commenter']['ip'] ?></ip>
			</commenter>
			<content><?php echo htmlspecialchars($comment['content']) ?></content>
			<written><?php echo $comment['date'] ?></written>
			<isFiltered>0</isFiltered>
			<password></password>
			<secret>0</secret>
		</comment>
<?php endforeach ?>
<?php foreach($post['attaches'] as $attach): ?>
		<attachment>
			<name><?php echo $attach['name'] ?></name>
			<attached><?php echo $post['date'] ?></attached>
			<content><?php echo $attach['content'] ?></content>
		</attachment>
<?php endforeach ?>
	</post>
<?php endforeach ?>
<?php foreach($notices as $notice): ?>
	<notice format="1.1" >
		<id><?php echo $notice['id'] ?></id>
		<visibility><?php echo $notice['status'] ?></visibility>
		<title><?php echo htmlspecialchars($notice['title']) ?></title>
		<content>
			<?php echo htmlspecialchars($notice['content']) ?>
		
		</content>
		<published><?php echo $notice['date'] ?></published>
		<created><?php echo $notice['date'] ?></created>
		<modified><?php echo $notice['date'] ?></modified>
	</notice>
<?php endforeach ?>
</blog><?php
}

/* End of file wxr2ttxml.php */