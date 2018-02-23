<?php
if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
	define('LF',"\r\n");
}else{
	define('LF',"\n");
}

define('PATH',"D:/Psync/dst/");
define('AuthUser',"root");
define('AuthPass',"root");

use Workerman\Worker;
use \Workerman\Lib\Timer;
require_once './Workerman/Autoloader.php';

$worker = new Worker('tcp://0.0.0.0:2873');
$worker->name = 'Psync Server';

$worker->onWorkerStart = function($worker)
{
	logger('Psync服务端启动');
};
$worker->onConnect = function($connection)
{
	$connection->authorized = false;
	$connection->STATE = 'auth';
	$connection->args = null;
	$connection->received = 0;
	// Timer::add(1,function()use($connection)
	// {
		// var_dump($connection->args);
	// });
};
$worker->onClose = function($connection)
{
	logger($connection->getRemoteIP().' 的连接断开');
	logger($connection->received);
};
$worker->onMessage = function($connection, $buffer)
{
	// echo $buffer."\n";
	switch($connection->STATE){
		case'auth':
			$data   = unpack('nuser_len/npass_len', $buffer);
			$offset = 4;
			$user = substr($buffer,$offset,$data['user_len']);
			$offset+= $data['user_len'];
			$pass = substr($buffer,$offset,$data['pass_len']);
			$offset+= $data['pass_len'];
			
			if($user==AuthUser && $pass==AuthPass){
				$connection->authorized = true;
				$connection->STATE = 'idle';
				logger('来自 '.$connection->getRemoteIP().' 的验证通过');
				$connection->send('authorized');
				$connection->send('requestSend');
			}else{
				logger('来自 '.$connection->getRemoteIP().' 的验证失败');
				$connection->send('unauthorized');
			}
			break;
		case'idle':
			//设置动作
			logger('设置动作');
			$data   = unpack('naction_len/narg_len', $buffer);
			$offset = 4;
			$action = substr($buffer,$offset,$data['action_len']);
			$offset+= $data['action_len'];
			$args = substr($buffer,$offset,$data['arg_len']);
			$offset+= $data['arg_len'];
			
			if(!in_array($action,array('sendFile'))){
				logger('设置动作错误'.$action);
				return;
			}
			
			switch($action){
				case'sendFile':
					$connection->STATE = $action;
					if($connection->args==null){
						$args = json_decode($args,true);
						logger('开始接收文件'.$args['file']);
						$connection->args = $args;
						$connection->args['received'] = 0;
						$dir = PATH . dirname($args['file']);
						if(!is_dir($dir)){
							make_dir($dir);
						}
						$file = PATH . $args['file'];
						
						@unlink($file);
						$connection->fileHandler = fopen($file,"a");
						// if($args['file']){
							
						// }
					}
					break;
			}
			break;
		case'sendFile':
			$buf_len = strlen($buffer);
			$connection->args['received'] += $buf_len;
			// $connection->received += $buf_len;
			fwrite($connection->fileHandler,$buffer);
			// logger('接收文件'.$connection->args['file'].'|'.$connection->args['received']);
			if($connection->args['received']>=$connection->args['filesize']){
				logger('完成接收文件'.$connection->args['file']);
				logger(md5_file(PATH . $connection->args['file']));
				fclose($connection->fileHandler);
				Timer::add(5,function()use($connection)
				{
					$connection->STATE = 'idle';
					$connection->args = null;
					$connection->send('requestSend');
				},array(),false);
			}
			break;
	}
	return;
	$offset = 0;
	$buf_len = strlen($buffer);
	while($offset<=$buf_len){
		$data   = unpack('naction_len/nfilename_len/ncontent_len', $buffer);
		$offset+= 6;
		$action = substr($buffer,$offset,$data['action_len']);
		$offset+= $data['action_len'];
		switch($action){
			case'send':
				$filename = substr($buffer,$offset,$data['filename_len']);
				$offset+= $data['filename_len'];
				$content = substr($buffer,$offset,$data['content_len']);
				$offset+= $data['content_len'];
				
				// var_dump($action);
				// var_dump($filename);
				// var_dump($content);
				
				$dir = PATH . dirname($filename);
				if(!is_dir($dir)){
					make_dir($dir);
				}
				$file = PATH . $filename;
				
				var_dump($file);
				$fp = fopen($file,"a");
				fwrite($fp,$content);
				fclose($fp);
				break;
		}
	}
	// logger('服务端接收数据<='.$buffer);
    // $connection->send("hello");
	// logger('服务端发送数据=>hello');
};

function make_dir($path){
	if(!is_dir($path)){
		$str = dirname($path);
		if($str){
			make_dir($str.'/');
			@mkdir($path,0777);
			chmod($path,0777);
		}
	}
}
function logger($str){
	if(!is_array($str)){
		$arr = explode(LF,$str);
	}else{
		$arr = $str;
	}
	foreach($arr as $v){
		if(trim($v)){
			echo date('Y-m-d H:i:s - ').$v.LF;
		}
	}
}

// 运行worker
Worker::runAll();