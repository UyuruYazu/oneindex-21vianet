<?php
	class onedrive{
		static $client_id;
		static $client_secret;
		static $redirect_uri;
		static $app_url;

		static function authorize_url(){
			$client_id = self::$client_id;
			$redirect_uri = self::$redirect_uri;
			$url ="https://login.chinacloudapi.cn/common/oauth2/authorize?response_type=code&client_id={$client_id}&redirect_uri={$redirect_uri}";
			$url .= '&state='.urlencode('http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);
			return $url;
		}
		
		static function authorize($code = "", $resource_id="00000003-0000-0ff1-ce00-000000000000"){
			$client_id = self::$client_id;
			$client_secret = self::$client_secret;
			$redirect_uri = self::$redirect_uri;

			$url = "https://login.chinacloudapi.cn/common/oauth2/token";
			$post_data = "client_id={$client_id}&redirect_uri={$redirect_uri}&client_secret={$client_secret}&code={$code}&grant_type=authorization_code&resource={$resource_id}";
			fetch::$headers = "Content-Type: application/x-www-form-urlencoded";
			$resp = fetch::post($url, $post_data);
			$data = json_decode($resp->content, true);
			return $data;
		}

		static function get_app_url($token){
			return "your onedrive for business hostname here"; // e.g: https://yourcompany-my.sharepoint.cn/
			/*fetch::$headers = "Authorization: bearer {$token}";

			$resp = fetch::get("https://api.office.com/discovery/v2.0/me/services");

			$data = json_decode($resp->content, true);
			if(!empty($data['value'])){
				return $data['value'][0]['serviceResourceId'];
			}
			return ;*/
		}

		static function access_token(){
			$token = config('@token');
			if($token['expires_on'] > time()+600){
				return $token['access_token'];
			}else{
				$refresh_token = config('refresh_token');
				$token = self::get_token($refresh_token);
				if(!empty($token['refresh_token'])){
					config('@token', $token);
					return $token['access_token'];
				}
			}
			return "";
		}
		
		static function get_token($refresh_token){
			$client_id = self::$client_id;
			$client_secret = self::$client_secret;
			$redirect_uri = self::$redirect_uri;
			$resource_id = self::$app_url;
			$url = "https://login.chinacloudapi.cn/common/oauth2/token";
			$post_data = "client_id={$client_id}&redirect_uri={$redirect_uri}&client_secret={$client_secret}&refresh_token={$refresh_token}&grant_type=refresh_token&resource={$resource_id}";
			fetch::$headers = "Content-Type: application/x-www-form-urlencoded";
			$resp = fetch::post($url, $post_data);
			$data = json_decode($resp->content, true);
			return $data;
		}

		static function human_filesize($size, $precision = 1) {
			for($i = 0; ($size / 1024) > 1; $i++, $size /= 1024) {}
			return round($size, $precision).['B','kB','MB','GB','TB','PB','EB','ZB','YB'][$i];
		}

		static function dir($path="/"){
			$token = self::access_token();
			fetch::$headers = "Authorization: bearer {$token}";
			if($path != '/'){
				$path = ':'.rtrim($path, '/').':/';
			}
			$url = self::$app_url."_api/v2.0/me/drive/root".$path."children?expand=thumbnails";
			$items = array();
			self::dir_next_page($url, $items);
			return $items;
		}

		static function dir_next_page($nextlink, &$items){
			$resp = fetch::get($nextlink);
			$data = json_decode($resp->content, true);
			if(empty($data)){
				return self::dir_next_page($nextlink, $items);
			}
			
			foreach((array)$data['value'] as $item){
				$items[$item['name']] = array(
					'name'=>$item['name'],
					'size'=>self::human_filesize($item['size']),
					'createdDateTime'=>strtotime($item['createdDateTime']),
					'lastModifiedDateTime'=>strtotime($item['lastModifiedDateTime']),
					'downloadUrl'=>$item['@content.downloadUrl'],
					'video'=>$item['video'],
					'image'=>$item['image'],
					'type'=>$item['file']['mimeType'],
					'folder'=>empty($item['folder'])?false:true
				);
				if(!empty($item['thumbnails'])){
					$url = $item['thumbnails'][0]['large']['url'];
					list($url, $tmp) = explode("&width=",$url);
					$items[$item['name']]['thumb'] = $url;
				}
			}

			if(!empty($data['@odata.nextLink'])){
				return self::dir_next_page($data['@odata.nextLink'], $items);
			}
		}

		static function thumbnails($path){
			$token = self::access_token();
			fetch::$headers = "Authorization: bearer {$token}";
			$url = self::$app_url."_api/v2.0/me/drive/root:/".$path.':/thumbnails';
			$resp = fetch::get($url);
			$data = json_decode($resp->content, true);
			if(!empty($data['value'][0])){
				return $data['value'][0];
			}
			return false;
		}

		//文件上传函数
		static function upload($path,$content){
			$token = self::access_token();
			fetch::$headers = "Authorization: bearer {$token}";
			$url = self::$app_url."_api/v2.0/me/drive/root:/".$path.':/content';	
			$resp = fetch::put($url,$content);
			$data = json_decode($resp->content, true);
			return @$data['@content.downloadUrl'];
		}
		
		static function create_upload_session($path){
			$path = self::urlencode($path);
			$token = self::access_token();

			fetch::$headers = "Authorization: bearer {$token}".PHP_EOL."Content-Type: application/json".PHP_EOL;
			$url = self::$app_url."_api/v2.0/me/drive/root:/".$path.":/createUploadSession";
			$post_data['item'] = array(
				'@microsoft.graph.conflictBehavior'=>'rename'
			);
			$post_data = json_encode($post_data);
			
			$resp = fetch::post($url,$post_data);
			$data = json_decode($resp->content, true);
			if($resp->http_code == 409){
				return false;
			}
			return $data;
		}

		static function upload_session($url, $file, $offset, $length=10240){
			$token = self::access_token();
			$file_size = self::_filesize($file);
			$content_length = (($offset+$length)>$file_size)?($file_size-$offset):$length;
			$end = $offset+$content_length-1;
			$post_data = self::file_content($file, $offset, $length);

			$request['url'] = $url;
			$request['headers'] = "Authorization: bearer {$token}".PHP_EOL;
			$request['headers'] .= "Content-Length: {$content_length}".PHP_EOL;
			$request['headers'] .= "Content-Range: bytes {$offset}-{$end}/{$file_size}";
			$request['post_data'] = $post_data;
			$resp = fetch::put($request);
			$data = json_decode($resp->content, true);
			return $data;
		}

		static function upload_session_status($url){
			$token = self::access_token();
			fetch::$headers = "Authorization: bearer {$token}".PHP_EOL."Content-Type: application/json".PHP_EOL;
			$resp = fetch::get($url);
			$data = json_decode($resp->content, true);
			if($resp->http_code == 404){
				return false;
			}
			return $data;
		}

		static function delete_upload_session($url){
			$token = self::access_token();
			fetch::$headers = "Authorization: bearer {$token}".PHP_EOL."Content-Type: application/json".PHP_EOL;
			$resp = fetch::delete($url);
			$data = json_decode($resp->content, true);
			var_dump($resp);
			return $data;
		}

		static function file_content($file, $offset, $length){
			$handler = fopen($file, "rb") OR die('获取文件内容失败');
			fseek($handler, $offset);
			
			return fread($handler, $length);
		}

		static function urlencode($path){
			$paths = explode('/', $path);
				foreach($paths as $k=>$v){
					$paths[$k] = rawurlencode($v);
				}
				return join('/',$paths);
			}
				static function _filesize($path){
		    if (!file_exists($path))
		        return false;
		    $size = filesize($path);
		    
		    if (!($file = fopen($path, 'rb')))
		        return false;
		    
		    if ($size >= 0){//Check if it really is a small file (< 2 GB)
		        if (fseek($file, 0, SEEK_END) === 0){//It really is a small file
		            fclose($file);
		            return $size;
		        }
		    }
		    
		    //Quickly jump the first 2 GB with fseek. After that fseek is not working on 32 bit php (it uses int internally)
		    $size = PHP_INT_MAX - 1;
		    if (fseek($file, PHP_INT_MAX - 1) !== 0){
		        fclose($file);
		        return false;
		    }
		    
		    $length = 1024 * 1024;
		    while (!feof($file)){//Read the file until end
		        $read = fread($file, $length);
		        $size = bcadd($size, $length);
		    }
		    $size = bcsub($size, $length);
		    $size = bcadd($size, strlen($read));
		    
		    fclose($file);
		    return $size;
		}
	}