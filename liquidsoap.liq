set("configure.run.dir","/var/run/liquidsoap")
set("configure.log.dir","/var/log/liquidsoap")
set("harbor.bindaddr","0.0.0.0")
set("harbor.max_connections",1)
set("init.daemon.change_user",true)
set("init.daemon.change_user.group","liquidsoap")
set("init.daemon.change_user.user","liquidsoap")
set("init.daemon.pidfile",true)
set("init.daemon.pidfile.path","<sysrundir>/<script>.pid")
set("log.file",true)
set("log.file.append",true)
set("log.file.path","<syslogdir>/<script>.log")
set("log.stdout",true)
set("log.level",3)
set("server.telnet",true)

pathToSocket="/var/run/liquidsoap/socket"
set("server.socket", true)
set("server.socket.path", pathToSocket)
set("server.socket.permissions", 511)

def streamcrossfade (a,b)
	add (normalize=false,
        [ sequence(merge=true,[ blank (duration=5.0),
        fade.initial (duration=6.0 ,b) ]),
        fade.final   (duration=6.0 ,a) ])
end

auto = audio_to_stereo(smart_crossfade(fade_out=3.0, fade_in=3.0, playlist(id="autoplaylist", conservative=true, "/var/www/htdocs/cache/All.m3u")))
live = audio_to_stereo(input.http(id="livestream", max=60.0, "http://66.77.88.99:8080/live"))
blank = audio_to_stereo(blank(id="blank", duration=15.0))
requestTrack = audio_to_stereo(request.queue(id="requestTrack", conservative=true))
full = fallback(track_sensitive=false, transitions = [ streamcrossfade, streamcrossfade ], [ live, requestTrack, auto, blank ])

output.icecast(
  %mp3(bitrate=192),
  description="Stream",
  genre="Misc",
  mount="/ao",
  host="66.77.88.99", port=8080, password="password", full
)
