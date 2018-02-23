<?php
if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
	define('LF',"\r\n");
}else{
	define('LF',"\n");
}

define('PATH',"D:/Psync/src/");
define('AuthUser',"root");
define('AuthPass',"root");
define('LOOP',false);

$filelist = array();

use Workerman\Worker;
use \Workerman\Lib\Timer;
use Workerman\Connection\AsyncTcpConnection;
require_once './Workerman/Autoloader.php';

$worker = new Worker();
$worker->name = 'Psync Client';
$worker->onWorkerStart = function($worker)
{
	logger('客户端已启动');
	doWorker();
	doScanner();
};
function doScanner(){
	global $filelist;
	$filelist = array();
	listDir(PATH);
}
function doWorker(){
	// 连接远程Psync服务器
	logger('正在连接服务端');
    $psync_conn = new AsyncTcpConnection("tcp://127.0.0.1:2873");
    // 连上后发送验证
    $psync_conn->onConnect = function($connection){
		logger('连接成功');
		$connection->authorized = false;
		$connection->sent = 0;
		//验证
		logger('正在以'.AuthUser.'的身份进行验证');
		$user_len = strlen(AuthUser);
		$pass_len = strlen(AuthPass);
		$msg = pack('nn',$user_len,$pass_len);
		$msg .= AuthUser.AuthPass;
		$connection->send($msg);
		
    };
    // 远程Psync服务器发来消息时
    $psync_conn->onMessage = function($connection, $data){
		global $filelist;
		switch($data){
			case'authorized':
				logger('验证通过');
				break;
			case'unauthorized':
				logger('验证失败');
				exit;
				break;
			case'requestSend':
				// logger('请求文件');
				$file = array_shift($filelist);
				if($file){
					sendfile($file,$connection);
				}else{
					$connection->close();
				}
				break;
		}
    };
    // 连接上发生错误时，一般是连接远程Psync服务器失败错误
    $psync_conn->onError = function($connection, $code, $msg){
        logger("error: $msg");
    };
    // 当连接远程Psync服务器的连接断开时
    $psync_conn->onClose = function($connection){
        logger("与服务器连接中断");
		if(LOOP){
			Timer::add(1,function(){
				doWorker();
				doScanner();
			},array(),false);
		}
    };
    // 执行连接操作
    $psync_conn->connect();
}
function sendfile($filename,$connection){
	$action = 'sendFile';
	$action_len = strlen($action);
	$filesize = filesize(PATH.$filename);
	$args = json_encode(array(
		'file'=>$filename,
		'filesize'=>$filesize,
	));
	$args_len = strlen($args);
	
	$msg = pack('nn',$action_len,$args_len);
	$msg .= $action.$args;
	$connection->send($msg);
	
	logger('开始传输'.$filename.'|'.$filesize);
	$connection->bufferFull = false;
	$connection->fileHandler = fopen(PATH.$filename,"r");
	$do_write = function()use($connection,$filename)
	{
		while($connection->bufferFull==false)
		{
			$buffer = fread($connection->fileHandler, 8192);
			if(feof($connection->fileHandler))
			{
				$connection->sent += strlen($buffer);
				$connection->send($buffer, true);
				logger('传输完成'.md5_file(PATH.$filename));
				logger($connection->sent);
				return;
			}
			$connection->sent += strlen($buffer);
			$connection->send($buffer, true);
		}
	};
	$connection->onBufferFull = function($connection)
	{
		$connection->bufferFull = true;
	};
	$connection->onBufferDrain = function($connection)use($do_write)
	{
		$connection->bufferFull = false;
		if(feof($connection->fileHandler))
		{
			return;
		}
		$do_write();
	};
	$do_write();
}
function listDir($dir){
	global $filelist;
	if(is_dir($dir)){
		if ($dh = opendir($dir)) {
			while (($file = readdir($dh)) !== false){
				$_file = $dir."/".$file;//绝对文件位置
				$__file = str_ireplace(PATH,'',$_file);//相对文件位置
				if((is_dir($_file)) && $file!="." && $file!="..")
				{
					listDir($dir."/".$file);
				}
				else
				{
					if($file!="." && $file!="..")
					{
						$filelist[] = $__file;
					}
				}
			}
			closedir($dh);
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