<?php

define('SOCKET', '/var/run/liquidsoap/socket');
define('STREAMNAME', 'ao');
define('LIVESTREAMNAME', 'livestream');
define('PLAYLISTNAME', 'autoplaylist');

if(isset($_GET['code'])) {highlight_file(__FILE__); die();}

function streamSend($cmds)
{
	$retval = array();

	try
	{
		$sock = socket_create(AF_UNIX,SOCK_STREAM, 0);

		if ($sock == FALSE)
		{
			throw new Exception('Unable to create socket: '.socket_strerror(socket_last_error()));
		}

		if (!socket_connect($sock, SOCKET, null))
		{
			throw new Exception('Unable to connect to '.SOCKET.' '.socket_strerror(socket_last_error()));
		}

		$totalCmds = substr_count($cmds, "\n");
		$cmds = explode("\n", $cmds."\n");
		$count = -1;
		while($count++ < $totalCmds)
		{
			$cmd = $cmds[$count]."\n";
			$length = strlen($cmd);
			$sent = socket_write($sock, $cmd, $length);

			if($sent === false)
			{
				throw Exception('Unable to write to socket: '.socket_strerror(socket_last_error()));
				return false;
			}

			if($sent < $length)
			{
				$cmd = substr($cmd, $sent);
				$length -= $sent;
				print('Message truncated: Resending: '.$cmd);
			}
			else
			{
				while ($buffer = socket_read($sock, 4096, PHP_NORMAL_READ))
				{
					if ($buffer == 'END'."\r")
					{
						break;
					}

					if($buffer != "\n") $retval[] = trim($buffer);
				}
			}
		}
	}
	catch (Exception $e)
	{
		$retval = 'Caught exception: '.$e->getMessage()."\n";
	}

	return $retval;
}

function streamSkipTrack()
{
	$response = streamSend('/'.STREAMNAME.'.skip');
	return $response;
}

function streamIsLive()
{
	$response = streamSend(LIVESTREAMNAME.'.status');
	return (preg_match('/connected/', $response[0]));
}

function streamParseJSON($lsResponse)
{
	$max = sizeof($lsResponse);

	$streamParsed = '{ ';
	for($i = 0; $i < $max - 1; $i++)
	{
		$streamParsed .= '"'.strstr($lsResponse[$i], '=', true).'" : '.ltrim(strstr($lsResponse[$i], '='), '=').', ';
	}
	$streamParsed = rtrim($streamParsed, ' ,');
	$streamParsed .= ' }';

	return $streamParsed;
}

function streamParse($lsResponse)
{
	$max = sizeof($lsResponse);

	$streamParsed = array();
	for($i = 0; $i < $max; $i++)
	{
		$streamParsed[strstr($lsResponse[$i], '=', true)] = ltrim(strstr($lsResponse[$i], '='), '=');
	}

	return $streamParsed;
}

function streamGetUpcomingTracks($json = false)
{
	$rawTracks = streamSend(PLAYLISTNAME.'.next');
	$total = sizeof($rawTracks);

	$tracksParsed = array();

	for($i = 0; $i < $total - 1; $i++)
	{
		$track = array();

		$track['position'] = $i;

		$track['status'] = trim(strstr($rawTracks[$i], ' ', true), '[]');
		$track['url'] = ltrim(strstr($rawTracks[$i], ' '));

		if($track['url'] === '')
		{
			$track['status'] = 'queued';
			$track['url'] = $rawTracks[$i];
		}

		$tracksParsed[] = $track;
	}

	if($json)
	{
		$tracks = '[';

		foreach($tracksParsed as $track)
		{
			$tracks .= ' { ';
			foreach($track as $field => $value)
			{
				$tracks .= '"'.$field.'" : "'.$value.'", ';
			}
			$tracks = rtrim($tracks, ', ');
			$tracks .= ' },';
		}
		$tracks = rtrim($tracks, ', ');

		$tracks .= ' ];';
	}
	else
	{
		$tracks = $tracksParsed;
	}

	return $tracks;
}

function streamGetCurrentTrack($json = false)
{
	$rid = streamSend('request.on_air');
	$rid = preg_split('/ /', $rid[0]);
	$lsResponse = streamSend('request.metadata '.$rid[0]);

	$lsResponse = ($json?streamParseJSON($lsResponse):streamParse($lsResponse));

	return $lsResponse;
}

function streamGetTrackList($json = false)
{
	$response = streamSend('/'.STREAMNAME.'.metadata');

	$response = streamParseTrackList($response, $json);

	return $response;
}

function streamParseTrackList($trackList, $json = false)
{
	$total = sizeof($trackList);

	$rid = 11;
	$tracks = array();
	for($i = 0; $i < $total; $i++)
	{
		if(preg_match('/--- \d* ---/', $trackList[$i]))
		{
			if(isset($tracks[$rid]))
			{
				$tracks[$rid] = ($json?streamParseJSON($tracks[$rid]):streamParse($tracks[$rid]));
			}

			$rid--;
			$tracks[$rid] = array();
		}
		else
		{
			$tracks[$rid][] = $trackList[$i];
		}
	}

	if(isset($tracks[$rid]))
	{
		$tracks[$rid] = ($json?streamParseJSON($tracks[$rid]):streamParse($tracks[$rid]));
	}

	$tracks = array_reverse($tracks);

	if($json)
	{
		$tracks = '[ '.implode(', ', $tracks).' ]';
	}

	return $tracks;
}

function streamAttemptRequestTrack($request)
{
	if((strpos($request, '/download/') != false) && (strpbrk($request, "\r\n \"'") == false))
	{
		return streamSend('requestTrack.push '.$request);
	}
	else
	{
		return false;
	}
}

function streamAttemptRequestAlbum($request)
{
	if(file_exists($request))
	{
		$tracks = file($request);
		$total = sizeof($tracks);

		if($total >= 1)
		{
			for($i = 1; $i < $total; $i+=2)
			{
				if(!streamAttemptRequestTrack(trim($tracks[$i])))
				{
					return false;
				}
			}

			return true;
		}
	}

	return false;
}

function getPageStreamJS()
{
	$output = '';
	$output .= <<< 'EOD'
<script type="text/javascript" src="?played"></script>
<script type="text/javascript" src="?upcoming"></script>
<script type="text/javascript" src="/js/jquery.js"></script>
<script type="text/javascript" src="/js/jquery.lazyload.js"></script>
<script type="text/javascript" src="/js/jquery.preview.js"></script>
<script type="text/javascript">
function lazyLoadInit()
{
        $('.miniart').lazyload({});
        $('.albumart').lazyload({});
}

function renderTracks(tracks, one)
{
	var i;
	var output = '';
	var identifier = '';
	var trackData;

	var startingPos = one?0:1;
	for(i = startingPos; i < tracks.length; i++)
	{
		var uri;

		if(tracks[i]['initial_uri']) uri = tracks[i]['initial_uri'];
		else if(tracks[i]['url']) uri = tracks[i]['url'];
		if(uri.indexOf('/download/') != -1)
		{
			identifier = ''+uri;
			identifier = identifier.replace(/http:\/\/archive.org\/download\//g, '');
			identifier = identifier.substring(0, identifier.indexOf('/'));
			if(one === true)
			{
				output += '<a class="preview" title="'+identifier+'" href="/details/'+identifier+'"><img class="albumart" data-original="/img/'+identifier+'" src="/images/no-cover-art.png" title="'+identifier+'" /><noscript><img class="albumart" src="/img/'+identifier+'" title="'+identifier+'" /></noscript></a><a href="/details/'+identifier+'" onClick="showslim(\\\''+identifier+'\\\');return false;"></a>';
			}
			else
			{
				output += '<a class="preview" title="'+identifier+'" href="/details/'+identifier+'"><img class="miniart" data-original="/thm/'+identifier+'" src="/images/no-cover-art.png" title="'+identifier+'" /><noscript><img class="miniart" src="/thm/'+identifier+'" title="'+identifier+'" /></noscript></a><a href="/details/'+identifier+'" onClick="showslim(\\\''+identifier+'\\\');return false;"></a>';
			}
		}

		trackData = '';
//		if(tracks[i]['artist']) trackData += tracks[i]['artist'];
//		if(tracks[i]['album']) trackData += tracks[i]['album']+' - ';
//		if(tracks[i]['tracknumber']) trackData += tracks[i]['tracknumber']+' - ';
//		if(tracks[i]['title']) trackData = '<a href="'+tracks[i]['initial_uri']+'">'+tracks[i]['title']+'</a> - ';
		output += trackData;

		output += ' ';

//		if(tracks[i]['genre']) output += tracks[i]['genre']+' - ';
//		if(tracks[i]['year']) output += tracks[i]['year'];

		output += '';

		if(one) return output;
	}

	return output;
}

function draw()
{
	var tempDiv = document.getElementById('playingDiv');
	tempDiv.innerHTML = renderTracks(playedTracks, true);

	var tempDiv = document.getElementById('playedDiv');
	tempDiv.innerHTML = renderTracks(playedTracks);

	var tempDiv = document.getElementById('upcomingDiv');
	tempDiv.innerHTML = renderTracks(upcomingTracks);

	lazyLoadInit();
}

window.onload=draw;
</script>
EOD;

	return $output;
}

function getPageStreamHTMLHead()
{
	$output = '';

	$output .= <<< 'EOD'
<html charset="utf-8">
<head>
<link rel="stylesheet" type="text/css" href="/css/aos.css">
<title>Stream</title>
</head>
<body>
EOD;

	return $output;
}

function getPageStreamHTML()
{
	$output = '';

	$output .= <<< 'EOD'
<h3 style="color: black;">Present</h3>
<div id="playingDiv"> </div>

<h3 style="color: black;">Past</h3>
<div id="playedDiv"> </div>

<h3 style="color: black;">Future</h3>
<div id="upcomingDiv"> </div>
EOD;

	return $output;
}

function getPageStreamHTMLFoot()
{
	$output = '';

	$output .= <<< 'EOD'
</body>
</html>
EOD;

	return $output;
}

if(true)
{
	if(isset($_GET['np']))
	{
		$output = '';

		if(isset($_GET['json']))
		{
			$output .= streamGetCurrentTrack(true);
			echo 'var playedTracks = '.$output;
		}
		else
		{
			$output = streamGetCurrentTrack(false);
			print_r($output);
		}
	}
	else if(isset($_GET['rt']))
	{
		$requestTrack = trim(strip_tags(strval($_GET['rt'])));

		if(streamAttemptRequestTrack($requestTrack))
		{
			echo 'Success!';
		}
		else
		{
		echo 'Fail!';
		}
	}
	else if(isset($_GET['ra']))
	{
		$requestAlbum = basename(strip_tags(strval($_GET['ra'])));

		$m3uFile = 'cache/m3u/'.$requestAlbum.'u.m3u';

		if(streamAttemptRequestAlbum($m3uFile))
		{
			echo 'Success!';
		}
		else
		{
			echo 'Fail!';
		}
	}
	else if(isset($_GET['skip']))
	{
		$returnVal = streamSkipTrack();

		if($returnVal[0] == 'Done')
		{
			header('Location: ');
		}
		else
		{
			echo 'Fail!';
		}
	}
	else if(isset($_GET['played']))
	{
		header('Cache-Control: no-cache, must-revalidated');
		header('Content-type: text/javascript');
		echo 'var playedTracks = '.streamGetTrackList(true);
	}
	else if(isset($_GET['upcoming']))
	{
		header('Cache-Control: no-cache, must-revalidated');
		header('Content-type: text/javascript');
		echo 'var upcomingTracks = '.streamGetUpcomingTracks(true);
	}
	else
	{
		header('Content-type: text/html');
		header('Cache-control: no-cache, must-revalidate');

		$output = '';
		$output .= getPageStreamHTMLHead();
		$output .= getPageStreamHTML();
		$output .= getPageStreamJS();
		$output .= getPageStreamHTMLFoot();

		echo $output;
	}
}
