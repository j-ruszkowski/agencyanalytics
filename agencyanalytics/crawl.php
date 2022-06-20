<?
   
   
   //Global program settings
   mb_internal_encoding('UTF-8');
   mb_regex_encoding('UTF-8');
   
   $allowed_hosts=array('www.agencyanalytics.com',
                        'agencyanalytics.com'
                       );
   
   //Global array of links to crawl
   $links = array();
   $records = array();
   
 

   //Resolve relative paths, i.e. no front slash, or contains ../ 
   function resolve_relative_path($path,$parent_path)
   {
      if(preg_match('/\.\.\//',$path))
      {
         //While a relative ../ exists, remove the folder one level up and the ../
         while(preg_match('/\.\.\//',$path))
         {  
            $path=preg_replace('/(?<!\.\.)[^\.\/]+\/\.\.\//','',$path);
            $path=preg_replace('/^\/?\.\.\//','',$path);                      
         }
                     
      }
      
      //Remove double slashes
      $path=preg_replace('/\/{2,}/','/',$path);
      if(substr($path,0,1)!='/')
      {
         $path = $parent_path.'/'.$path;
      } 
      
      return($path);
                           
   }
   
   
   //Pass error array to function, output errors and exit program
   function page_error($error)
   {
         ?>
                        <div class="divider" style="color: white; background-image: linear-gradient(to right, #790000 , White);">Error<? if (count($error)>1) echo 's'; ?>:
                        <!--div id="summary" style="height: 100%; width: 100%"-->
                           <?  foreach($error as $value)
                               {
                                  echo '<br/>'.$value."\n"; 
                               }
                           ?>
                        </div>
         <?   
      exit;
   }
   
   //Open link and parse page and capture details in $records
   function parse_page($link_num)
   {
      global $links;
      global $records;
      global $check_img_hash;
      
      $record=array();
   
      //Break down URL and keep protocol, host, full path and parent path (path)
      $record['url']=$links[$link_num];
 
      $url = parse_url($record['url']);
      $record['protocol']=$url['scheme'];
      $record['host']=$url['host'];
      $record['full_path']=$url['path'];
      
      $path = explode('\/',$url['path']);
      array_pop($path);
      $record['path'] = join('\/',$path) ?: '/'; 

      //Capture start time of open request
      $start_time = time();
            
      //Initiate opening of URL
      $handle = fopen($record['url'],'r');
  
   
      //Match response code
      preg_match_all('/^http\/[\d\.]+ (\d+)/i',$http_response_header[0],$matches);   
      $record['response']=$matches[1][0];

      //If the response is 200, 301 or 304 (path redirect), then parse the file
      //Do not follow other redirects, or parse errors 
      if($record['response']=='200'||$record['response']=='301'||$record['response']=='304')
      {
         //Read stream 
         $contents = stream_get_contents($handle);
         fclose($handle);
         
         if($contents)
         {
            $record['load_time']=time()-$start_time;
            
            //Get title and strip all non-word characters
            preg_match_all('/<title[^<>]*>(.+)<\/title>/is',$contents,$matches);
            
            //Convert unicode to apostrophe
            $title = preg_replace('/\x{2019}/u',"'",$matches[1][0]);
   
            $record['title']= $title;
            
            //Convert all non-breaking spaces, html-encoded characters and special characters to spaces
            $title = preg_replace(array('/&nbsp;/i','/\&[\w\#]+;/','/[^\w\- \'\p{Latin}](?!\d)/u'),' ',$title);
            $split=preg_split('/\s+/',$title,-1,PREG_SPLIT_NO_EMPTY);
            //Count title words, not required, but counting everything else 
            $record['title_words_count']=count($split);           

            //Get anchors (links)
            preg_match_all('/<a href=([\'"])(.+)\1/iUs',$contents,$matches);
            $link_array = $matches[2];

            //Get list of internal and external links with their proper URLs 
            foreach($link_array as $link)
            {
               $url = parse_url($link);
               
               //Find internal links for same server or the allowed host specified
               if(preg_match('/^'.preg_quote($allowed_host).'$/i',$url['host']) || !$url['host'])
               {
                  $record['internal_links'][]=$record['protocol']."://".$record['host'].resolve_relative_path($url['path'],$record['path']);
               }
               else
               {
                  $record['external_links'][]=$url['scheme']."://".$url['host'].resolve_relative_path($url['path'],$record['path']);
               }              
            }
            
            //Unique arrays of internal and external links
            $record['internal_links']=array_unique($record['internal_links']);
            $record['external_links']=array_unique($record['external_links']);
            
            
            
            $link_queue = $record['internal_links'];
            //Add internal links to the master list $links until max of 6 links or until no more links available from this page
            while(count($links)<6 && count($link_queue))
            {
               $link=array_shift($link_queue);
               $links[]=$link;
            }  
            
            //Only the body can contain visible words and images, so grab body contents
            preg_match_all('/<body[^<>]*>(.+)<\/body>/is',$contents,$matches);
            //Remove alt tags as they can contain HTML tags 
            $body = preg_replace('/alt=([\"\']).+(?<!\\\\)\1/Uis',' ',$matches[1][0]);          
            
            //Capture all img sources (src and data-src)
            //Skip pure SVG tags, as uniqueness cannot be determined without parsing
            preg_match_all('/<img [^\>]*src=([\'"])(.+)\1/iUs',$body,$matches);
            $img_link_array = $matches[2];
            
            preg_match_all('/<img [^\>]*data-src=([\'"])(.+)\1/iUs',$body,$matches);
            $img_link_array=array_merge($img_link_array,$matches[2]);
            
            //If the src attribute begins with 'data:' then we need the data-src attribute and the src can be deleted from the array
            for($i=0; $i<count($img_link_array); $i++)
            {
               if(substr($img_link_array[$i],0,5)=='data:')
               {
                  unset($img_link_array[$i]);
               }
            }
            
            //Unique images by path without parsing URL
            $img_link_array = array_unique($img_link_array);
            
            foreach($img_link_array as $img_link)
            {
               $url = parse_url($img_link);
               if(!$url['host'])
               {
                  $record['images'][]=$record['protocol']."://".$record['host'].resolve_relative_path($url['path'],$record['path']);
               }
               else
               {
                  $record['images'][]=$url['protocol']."://".$url['host'].resolve_relative_path($url['path'],$record['path']);
               }
            }
            
            //Unique array of image URLs
            $record['images']=array_unique($record['images']);
            
            //If the parse images for uniqueness is checked, then open all image links and create a hash of the contents
            if($check_img_hash)
            {
               foreach($record['images'] as $img_link)
               {
                  $handle = fopen($img_link,'rb');
                  $image = stream_get_contents($handle);
                  if($image)
                  {
                     $record['images_hash'][]=md5($image);
                  }
               }
               
               //Include the image loads in the load time if hash checks are enabled
               $record['load_time']=time()-$start_time;
            
               
               //Unique array of image hashes
               $record['images_hash']=array_unique($record['images_hash']);               
            }
            
            
            //Remove script and comment tags from body, interfering alt attributes that can contain tags themselves, and any newline/carriage returns
            $body = preg_replace(array('/<(script)[^<>]*>.+<\/\1>/Uis','/<(!--|img|source)[^<>]*(--|\/)>/Uis','/[\n\r]/'),' ',$body);
            
            //Remove all other tags, leaving just the contents
            $body = preg_replace('/<\/?[^\s\/]+[^<>]*>/i',' ',$body);

            //Convert all extended apostrophes to regular ones
            $body = preg_replace('/\x{2019}/u',"'",$body);
            //Convert all non-breaking spaces, html-encoded characters and special characters to spaces
            $body = preg_replace(array('/&nbsp;/i','/\&[\w\#]+;/','/[^\w\- \'\p{Latin}](?!\d)/'),' ',$body);
            
            //Split the body on spaces to isolate all words
            $split = preg_split('/\s/',$body,-1,PREG_SPLIT_NO_EMPTY);
            
            $words = array();
            //Count occurrences of each word
            foreach($split as $word)
            {
               $words[strtolower($word)]++;
            }
            
            
            $record['total_words_count']=count($split);           
            $record['total_words_array']=$split;
            $record['distinct_words_count']=count($words);
            $record['distinct_words_array']=$words;         
      
         }

      }   
         
      //Add this page record to the global collection of records
      $records[]=$record;
      
   }
  

//---------------------------------------------------------------------------------------------------------------------------------------------
//Main Execution
//---------------------------------------------------------------------------------------------------------------------------------------------
   $current_link=0;
   $error = array();
   
   //POST url validation
   if($_POST['url'])
   {
      $url = $_POST['url'];
      
      //Break down URL into components
      $url = parse_url($url);
      $protocol=$url['scheme'];
      $host=$url['host'];
      $path=$url['path'];
      
      //Check if allowed host
      if(!in_array($host,$allowed_hosts))
      {
         $error[] = "Host is not allowed: $host";
      }
      
      //Check protocol is https or http
      if($protocol!='https'&&$protocol!='http')
      {
         $error[] = "Invalid protocol: $protocol. Only http or https allowed.";
      }
      
      //If no errors encountered, add the URL to our global list of links
      if(!count($error))
      {
         $links[]=$_POST['url'];  
      }
   }
   else
   {
      $error[] = "No URL supplied.";
   }
   
   //If we have an error so far, run the error reporting and exit
   if(count($error))
   {
      page_error($error);
   }

   //If the parse images for uniqueness is set to true, then set our global variable for check_img_hash to true
   if($_POST['img_hash']=='true')
   {
      $check_img_hash=true;
   }
   
   //While we have more links to parse, keep parsing. Links are added as they become available during the parsing
   while($current_link<count($links))
   {
      parse_page($current_link);
      $current_link++;  
   }
    
  
   //Output the final results
      $images = array();
      $internal_links = array();
      $external_links = array();
      $load_time = 0;
      $total_word_count = 0;
      $total_title_length=0;
      
      
      //Tally up all of the summary information
      for($i=0; $i<count($records); $i++)
      {
         
         $load_time+=$records[$i]['load_time'];
         $total_word_count+=$records[$i]['total_words_count'];
         $total_title_length+=strlen($records[$i]['title']);
         
         
         for($j=0; $j<count($records[$i]['images']); $j++)
         {
            $images[] = $records[$i]['images'][$j];         
         }

         for($j=0; $j<count($records[$i]['internal_links']); $j++)
         {
            $internal_links[] = $records[$i]['internal_links'][$j];
         }
         
         for($j=0; $j<count($records[$i]['external_links']); $j++)
         {
            $external_links[] = $records[$i]['external_links'][$j];
         }

         
      }
      
      $images = array_unique($images);
      $internal_links = array_unique($internal_links);
      $external_links = array_unique($external_links);
      
      //Summary Tables
      ?>
              <div class="divider">Links Traversed</div>
                 <div id="links_summary" style="height: 100%; width: 100%">
                     <table>
                        <th>Link Number</th>
                        <th>Link</th>
                        <th>HTTP Response Code</th>
                        <?
                           foreach($records as $key=>$record)
                           {
                             ?>
                             <tr class="<? if($key%2) echo 'oddrow'; else echo 'evenrow'; ?>">
                                <td class="numbers"><? echo $key+1; ?></td>
                                <td><? echo $record['url']; ?></td>
                                <td class="numbers"><? echo $record['response']; ?></td>
                             </tr>
                             <?  
                           }
                           
                        ?>
                           
                     </table>
               </div>
               <div class="divider">Summary</div>
                  <div id="summary" style="height: 100%; width: 100%">   
                     <table>
                        <th>Category</th>
                        <th>Count</th>
                        <tr class="oddrow"><td>Number of Pages Crawled:</td><td class="numbers"><? echo count($links); ?></td></tr> 
                        <tr class="evenrow"><td>Number of Unique Images:</td><td class="numbers"><? echo count($images); ?></td></tr>
                        <tr class="oddrow"><td>Number of Unique Internal Links:</td><td class="numbers"><? echo count($internal_links); ?></td></tr>
                        <tr class="evenrow"><td>Number of Unique External Links:</td><td class="numbers"><? echo count($external_links); ?></td></tr>
                        <tr class="oddrow"><td>Average Page Load (s):</td><td class="numbers"><? echo number_format($load_time/count($links),2,".",","); ?></td></tr>
                        <tr class="evenrow"><td>Average Word Count:</td><td class="numbers"><? echo number_format($total_word_count/count($links),2,".",","); ?></td></tr> 
                        <tr class="oddrow"><td>Average Title Length:</td><td class="numbers"><? echo number_format($total_title_length/count($links),2,".",","); ?></td></tr> 
                     </table>
                  </div>
               </div>
      
               <div class="divider" onclick="$('#statistics').slideToggle(1000); if($('#stats_arrow').html()=='&#x25bc;') $('#stats_arrow').html('&#x25b2;'); else $('#stats_arrow').html('&#x25bc;'); $('html, body').animate({scrollTop:$(document).height()}, 'fast');"><span id="stats_arrow">&#x25bc;</span>&nbsp;Statistics</div>
                  <div id="statistics" style="display: none; height: 100%; width: 100%">
                     <table>
                        <th>Link Number</th>
                        <th>Unique Internal Links</th>
                        <th>Unique External Links</th>
                        <th>Unique Images (URL)</th>
                        <? if ($check_img_hash)
                        {
                          ?><th>Unique Images (hash)</th><?
                        } 
                        ?>                      
                        <th>Load Time (s)</th>
                        <th>Word Count</th>
                        <th>Unique Word Count</th>
                        <th>Title Length</th> 
                        <?
                           foreach($records as $key=>$record)
                           {
                              ?>
                            <tr class="<? if($key%2) echo 'oddrow'; else echo 'evenrow'; ?>">
                               <td class="numbers"><? echo $key+1; ?></td>
                               <td class="numbers"><? echo count($record['internal_links']); ?></td>
                               <td class="numbers"><? echo count($record['external_links']); ?></td>
                               <td class="numbers"><? echo count($record['images']); ?></td>
                               <? if ($check_img_hash)
                                 {
                                   ?><td class="numbers"><? echo count($record['images_hash']); ?></td><?
                                 } 
                               ?>
                               <td class="numbers"><? echo $record['load_time']; ?></td>
                               <td class="numbers"><? echo $record['total_words_count']; ?></td>
                               <td class="numbers"><? echo $record['distinct_words_count']; ?></td>
                               <td class="numbers"><? echo strlen($record['title']); ?></td>
                            </tr>
                              <?
                           }
                        ?>
                        
                     </table>
                  </div>
      
               <div class="divider" onclick="$('#word_cloud').slideToggle(1000); if($('#word_cloud_arrow').html()=='&#x25bc;') $('#word_cloud_arrow').html('&#x25b2;'); else $('#word_cloud_arrow').html('&#x25bc;'); $('html, body').animate({scrollTop:$(document).height()}, 'fast');"><span id="word_cloud_arrow">&#x25bc;</span>&nbsp;Word Cloud</div>
                  <div id="word_cloud" style="display: none; height: 100%; width: 100%"></div>
                  <script>
                  <?
                  
                  //Let's create a word cloud of the word counts
                  $word_cloud = array();
                  $word_cloud_vars = array();
                  
                  foreach($records as $record)
                  {
                     foreach($record['distinct_words_array'] as $key=>$value)
                     {
                        $word_cloud[$key]+=$value;
                     }  
                  }
                  ?>
                        var words = [<?
                           foreach($word_cloud as $key=>$value)
                           {
                              $word_cloud_vars[] = '{text: "'.$key.'", weight: '.$value.'}';
                           }
                           echo join(",",$word_cloud_vars);
                        ?>];
                        $('#word_cloud').jQCloud(words, {width: 800,height: 400});
                  </script>
      <?

?>