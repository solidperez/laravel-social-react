<?php

namespace App\Traits\Linkedin;

use Carbon\Carbon;
use Laravel\Socialite\Facades\Socialite;
use \Artesaos\LinkedIn\Facades\LinkedIn;
use Illuminate\Support\Facades\Cache;

trait LinkedinTrait
{
    /**
     * Used to switch between users by using their corresponding
     * access fokens for login
     */
    public function publish($post)
    {
        $client =new \GuzzleHttp\Client();
        $result = $client->request('POST', "https://api.linkedin.com/v2/ugcPosts", [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Accept' => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0'
            ],
            'json' => $post
        ]);

        return json_decode($result->getBody()->getContents());
    }

    public function publishScheduledPost($scheduledPost)
    {
        try{
            $payload = unserialize($scheduledPost->payload);
            $images = $payload['images'];
            $timezone = $payload['scheduled']['publishTimezone'];

            $imageUrl = "";

            $mediaIds = [];
            $payload_channel = unserialize($this->payload);

            $urnType = $this->account_type == "page" ? "organization" : "person";
            $id = $payload_channel->id;
            $text = $scheduledPost->content;

            $post["author"] = "urn:li:$urnType:$id";
            $post["lifecycleState"] = "PUBLISHED";
            if($text){
                $post["specificContent"]["com.linkedin.ugc.ShareContent"]["shareCommentary"] = ["text" => $text];
            }
            
            if(!$images){
                $post["specificContent"]["com.linkedin.ugc.ShareContent"]["shareMediaCategory"] = "NONE";
            } else {
                foreach($images as $image){
                $relativePath = str_replace('storage', 'public', $image['relativePath']);
                $uploadResponse = $this->uploadMediaV2($relativePath);
                
                if(!$uploadResponse) continue;

                $mediaIds[] = ["status"=> "READY", "media" => $uploadResponse];
                }

                $post["specificContent"]["com.linkedin.ugc.ShareContent"]["shareMediaCategory"] = "IMAGE";
                $post["specificContent"]["com.linkedin.ugc.ShareContent"]["media"] = $mediaIds;
                
            }
            
            $link = findUrlInText($text);
            
            $post["visibility"]["com.linkedin.ugc.MemberNetworkVisibility"] = "PUBLIC";
            $result = $this->publish($post);

            $now = Carbon::now();

            $scheduledPost->posted = 1;
            $scheduledPost->status = null;

            if(!isset($result->id)){
                $scheduledPost->posted = 0;
                $scheduledPost->status = -1;
                $scheduledPost->save();
                throw new \Exception('Something is wrong with the token');
            }

            $scheduledPost->scheduled_at = $now;
            $scheduledPost->scheduled_at_original = Carbon::parse($now)->setTimezone($timezone);
            $scheduledPost->save();

            return $result;

        }catch(\Exception $e){

            if($scheduledPost){
                $scheduledPost->posted = 0;
                $scheduledPost->status = -1;
            }

            throw $e;
        }
    }


    public function uploadMedia($relativePath)
    {
        try {
            if(!$relativePath) return;

            $content = \Storage::get($relativePath);
            $url="https://api.linkedin.com/media/upload";
            $client =new \GuzzleHttp\Client();
            $fileName = basename($relativePath);

            $result = $client->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Accept' => 'application/json',
                    'X-Restli-Protocol-Version' => '2.0.0'
                ],
                'multipart' => [
                    [
                        'name' => 'fileupload',
                        'contents' => $content,
                        'filename' => $fileName,
                    ],
                ]
            ]);

            return json_decode($result->getBody()->getContents());
        }catch(\Exception $e){}

        return false;
    }

    public function uploadMediaV2($relativePath)
    {
        try {
            if(!$relativePath) return;
            
            $content = \Storage::get($relativePath);
            $url="https://api.linkedin.com/v2/assets?action=registerUpload";
            $client =new \GuzzleHttp\Client();
            $fileName = basename($relativePath);

            $urnType = $this->account_type == "page" ? "organization" : "person";
            $payload = unserialize($this->payload);

            $id = $payload->id;
            $params = [
                "registerUploadRequest" => [
                    "recipes" => [
                        "urn:li:digitalmediaRecipe:feedshare-image"
                    ],
                    "owner" => "urn:li:$urnType:$id",
                    "serviceRelationships" => [
                        [
                            "relationshipType" => "OWNER",
                            "identifier" => "urn:li:userGeneratedContent"
                        ]
                    ]
                ]
            ];
            
            $body = json_encode($params);
            $image_request = $client->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Accept' => 'application/json',
                    'X-Restli-Protocol-Version' => '2.0.0'
                ],
                'body' => $body
            ]);
            
            $image_request_result = json_decode($image_request->getBody()->getContents(), true);

            $uploadUrl = $image_request_result['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'];
            $imageId = $image_request_result['value']['asset'];
            
            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                ],
                'body' => $content 
            ];
            $result = $client->request('PUT', $uploadUrl, $options);
            
            return $imageId;

        }
        catch(\Exception $e){
        }

        return false;

    }


    public function getAvatar()
    {

        try{
            $key = $this->id . "-linkedinAvatar";
            $minutes = 1;
            return Cache::remember($key, $minutes, function () {
                $profile = Socialite::driver("linkedin")->userFromToken($this->access_token);

                if($profile){
                    return $profile->avatar;
                }

                return public_path()."/images/dummy_profile.png";
            });
        }catch(\Exception $e){
            getErrorResponse($e, $this->global);
            return false;
        }
    }

    public function getTimeline()
    {
       // LinkedIn::setAccessToken($this->access_token);

       // $result = LinkedIn::get('v2/shares?q=owners&owners={URN}&sharesPerOwner=100');

        $client =new \GuzzleHttp\Client();

        $payload = unserialize($this->payload);

        $result = $client->request('GET', "https://api.linkedin.com/v2/shares", [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Accept' => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0'
            ],
            'query' => ""
        ]);

        return json_decode($result->getBody()->getContents());
    }

    public function getPages()
    {
        $client =new \GuzzleHttp\Client();

        $result = $client->request('GET', "https://api.linkedin.com/v2/organizationalEntityAcls", [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Accept' => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0'
            ],
            'query' => "q=roleAssignee&role=ADMINISTRATOR&state=APPROVED&projection=(elements*(organizationalTarget~(id, name, vanityName, logoV2(original~:playableStreams))))"
        ]);

        $result = $result->getBody()->getContents();

        $result = json_decode($result);

        if(is_object($result) && property_exists($result, "elements")){
            foreach($result->elements as $item){
                $organizationalTarget = "organizationalTarget~";
                $original = "original~";
                $item->avatar = $item->$organizationalTarget->logoV2->$original->elements[0]->identifiers[0]->identifier;
                $item->id = $item->$organizationalTarget->id;
                $item->vanityName = $item->$organizationalTarget->vanityName;
                $item->name = $item->$organizationalTarget->name->localized->en_US;
            }

            $result = $result->elements;
        }

        return $result;
    }

    public function getPageDetails($pageId)
    {
        $client =new \GuzzleHttp\Client();

        $result = $client->request('GET', "https://api.linkedin.com/v2/organizations/$pageId", [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Accept' => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0'
            ],
            'query' => ""
        ]);

        return json_decode($result->getBody()->getContents());
    }

    public function getPosts($sDate=null, $eDate=null)
    {
        $string = rawurlencode(utf8_encode("urn:li:organization:$this->original_id"));

        $encoded_urn = str_replace("-","%3A", $string);

        $client =new \GuzzleHttp\Client();

        $result = $client->request('GET', "https://api.linkedin.com/v2/ugcPosts", [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip',
                'X-Restli-Protocol-Version' => '2.0.0'
            ],
            'query' => "q=authors&authors=List({$encoded_urn})"
        ]);

        $decoded_result = json_decode($result->getBody()->getContents());

        if (is_object($decoded_result) && property_exists($decoded_result, 'elements'))

        return $decoded_result->elements;

        return [];
    }

    public function getFollowers($sDate = null, $eDate = null)
    {
        $string = rawurlencode(utf8_encode("urn:li:organization:$this->original_id"));

        $encoded_urn = str_replace("-", "%3A", $string);

        $client = new \GuzzleHttp\Client();

        $result = $client->request('GET', "https://api.linkedin.com/v2/networkSizes/$encoded_urn", [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip',
                'X-Restli-Protocol-Version' => '2.0.0'
            ],
            'query' => "edgeType=CompanyFollowedByMember"
        ]);

        return json_decode($result->getBody()->getContents());
    }

    public function shareStatistics($sDate = null, $eDate = null)
    {
        $string = rawurlencode(utf8_encode("urn:li:organization:$this->original_id"));

        $encoded_urn = str_replace("-", "%3A", $string);

        $client = new \GuzzleHttp\Client();

        $result = $client->request('GET', "https://api.linkedin.com/v2/organizationalEntityShareStatistics", [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip',
                'X-Restli-Protocol-Version' => '2.0.0'
            ],
            'query' => "q=organizationalEntity&organizationalEntity=$encoded_urn&timeIntervals=(timeRange:(start:$sDate,end:$eDate),timeGranularityType:DAY)"
        ]);

        return json_decode($result->getBody()->getContents());
    }

    public function followerStatistics($sDate = null, $eDate = null)
    {
        $string = rawurlencode(utf8_encode("urn:li:organization:$this->original_id"));

        $encoded_urn = str_replace("-", "%3A", $string);

        $client = new \GuzzleHttp\Client();

        $result = $client->request('GET', "https://api.linkedin.com/v2/organizationalEntityFollowerStatistics", [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip',
                'X-Restli-Protocol-Version' => '2.0.0'
            ],
            'query' => "q=organizationalEntity&organizationalEntity=$encoded_urn&timeIntervals=(timeRange:(start:$sDate,end:$eDate),timeGranularityType:DAY)"
        ]);

        return json_decode($result->getBody()->getContents());
    }

    public function socialActions($id)
    {
        $string = rawurlencode(utf8_encode($id));

        $encoded_urn = str_replace("-", "%3A", $string);

        $client = new \GuzzleHttp\Client();

        $result = $client->request('GET', "https://api.linkedin.com/v2/socialActions/$encoded_urn", [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Accept' => 'application/json',
                'Accept-Encoding' => 'gzip',
                'X-Restli-Protocol-Version' => '2.0.0'
            ],
            'query' => ""
        ]);

        return json_decode($result->getBody()->getContents());
    }
}
