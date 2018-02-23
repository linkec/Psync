<?php 
namespace Qiniu;

class QEtag 
{
	define('BLOCK_BITS', 22);
	define('BLOCK_SIZE', 1 << BLOCK_BITS);
	
	protected function PackArray($v, $a) {
		return call_user_func_array('pack', array_merge(array($v),(array)$a));
	}

	protected function BlockCount($fsize) {
		return (($fsize + (BLOCK_SIZE - 1)) >> BLOCK_BITS);
	}

	protected function URLSafeBase64Encode($str) // URLSafeBase64Encode
	{
		$find = array('+', '/');
		$replace = array('-', '_');
		return str_replace($find, $replace, base64_encode($str));
	}

	protected function CalSha1($fhandler) {
		$fdata = fread($fhandler, BLOCK_SIZE);
		$sha1Str = sha1($fdata, true);
		$err = error_get_last();
		if ($err != null) {
			return array(null, $err);
		}
		$byteArray = unpack('C*', $sha1Str);
		return array($byteArray, null);
	}

	public function GetEtag($filename) {
		if (!is_file($filename)) {
			$err = array ('message' => 'Can not open ' . $filename . ' as a file.');
			return array(null, $err);
		}
		$fhandler = fopen($filename, 'r');
		$err = error_get_last();
		if ($err != null) {
			return array(null, $err);
		}

		$fstat = fstat($fhandler);
		$fsize = $fstat['size'];
		$blockCnt = $this->BlockCount($fsize);
		$sha1Buf = array();

		if ($blockCnt <= 1) {
			$sha1Buf[] = 0x16;
			list($sha1Code, $err) = $this->CalSha1($fhandler);
			if ($err != null) {
				return array(null, $err);
			}
			fclose($fhandler);
			$sha1Buf = array_merge($sha1Buf, $sha1Code);
		} else {
			$sha1Buf[] = 0x96;
			$sha1BlockBuf = array();
			for ($i=0; $i < $blockCnt; $i++) {
				list($sha1Code, $err) = $this->CalSha1($fhandler);
				if ($err != null) {
					return array(null, $err);
				}
				$sha1BlockBuf = array_merge($sha1BlockBuf, $sha1Code);
			}
			$tmpData = $this->PackArray('C*', $sha1BlockBuf);
			$tmpFhandler = tmpfile();
			fwrite($tmpFhandler, $tmpData);
			fseek($tmpFhandler, 0);
			list($sha1Final, $_err) = $this->CalSha1($tmpFhandler);
			$sha1Buf = array_merge($sha1Buf, $sha1Final);
		}
		$etag = $this->URLSafeBase64Encode($this->PackArray('C*', $sha1Buf));
		return array($etag, null);
	}
}
?>
