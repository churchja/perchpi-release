<?php
error_reporting(E_ERROR);
ini_set('display_errors',1);

session_start();
require_once "scripts/common.php";
$user = get_user();
$home = get_home();

$fetch = shell_exec("sudo -u".$user." git -C ".$home."/BirdNET-Pi fetch 2>&1");
$str = trim(shell_exec("sudo -u".$user." git -C ".$home."/BirdNET-Pi status"));
if (preg_match("/behind '.*?' by (\d+) commit(s?)\b/", $str, $matches)) {
  $num_commits_behind = $matches[1];
}
if (preg_match('/\b(\d+)\b and \b(\d+)\b different commits each/', $str, $matches)) {
    $num1 = (int) $matches[1];
    $num2 = (int) $matches[2];
    $num_commits_behind = $num1 + $num2;
}
if (stripos($str, "Your branch is up to date") !== false) {
  $num_commits_behind = '0';
}
$_SESSION['behind'] = $num_commits_behind;
$_SESSION['behind_time'] = time();

$restore = "cat $home/BirdSongs/restore.log";
$max_upload_size = floor(disk_free_space("$home/BirdNET-Pi/") / 1.001);

?><html>
<meta name="viewport" content="width=device-width, initial-scale=1">
<br>
<br>
<script>
var seconds = 0;
function update() {
  if(confirm('Are you sure you want to update?')) {
    setInterval(function(){ seconds += 1; document.getElementById('updatebtn').innerHTML = "Updating: <pre id='timer' class='bash'>"+new Date(seconds * 1000).toISOString().substring(14, 19)+"</pre>"; }, 1000);
    return true;
  } else {
    return false;
  }
}
</script>
<div class="systemcontrols">
<form action="views.php" method="POST"><?php echo csrf_field(); /* perchpi:s21a-csrf-v1 */ ?>
  <div>
    <button type="submit" name="submit" value="sudo reboot" onclick="return confirm('Are you sure you want to reboot?')">Reboot</button>
  </div>
  <!-- perchpi:s21a-no-update-v1 (D6): no Update button. update_birdnet.sh resets to an
       upstream-style checkout and can't reach the private repo. -->
  <div>
    <button type="submit" name="submit" value="sudo shutdown now" onclick="return confirm('Are you sure you want to shutdown?')">Shutdown</button>
  </div>
  <div>
    <button type="submit" name="submit" value="sudo -u <?php echo htmlspecialchars($user, ENT_QUOTES); ?> clear_all_data.sh" onclick="return confirm('Clear ALL Data? Note that this cannot be undone and will take up to 90 seconds.')">Clear ALL data</button>
  </div>
</form>
<div id="container">
  <button id="pickfile" type="button" href="javascript:;">Restore data</button>
</div>
<div><a href="scripts/backup.php" download ><button onclick="return confirm('Download backup? Note that this could take a long time.')">Backup data</button></a></div>
<?php
  $cmd="cd ".$home."/BirdNET-Pi && sudo -u ".$user." git rev-list --max-count=1 HEAD";
  $curr_hash = shell_exec($cmd);
?>
  <p style="font-size:11px;text-align:center"></br></br>Running version: </p>
  <a href="https://github.com/Nachtzuster/BirdNET-Pi/commit/<?php echo $curr_hash; ?>" target="_blank">
    <p style="font-size:11px;text-align:center;box-sizing: border-box"><?php echo $curr_hash; ?></p>
  </a>
  <pre id="console" style="text-align:center"></pre>
</div>
<script type="text/javascript">
// based on Custom example logic

var uploader = new plupload.Uploader({
    runtimes : 'html5',
    browse_button : 'pickfile', // you can pass an id...
    container: document.getElementById('container'), // ... or DOM Element itself
    url : 'scripts/restore.php',
    headers : { 'X-CSRF-Token': window.PP_CSRF || '' },  // perchpi:s21a-csrf-v1
    chunk_size: '2mb',
    multi_selection: false,

    filters : {
        max_file_size : '<?php echo "$max_upload_size"; ?>',
        mime_types: [
            {title : "Tar files", extensions : "tar"}
        ]
    },

    init: {
        FilesAdded: function(up, files) {
            uploader.start();
        },

        UploadProgress: function(up, file) {
            if (file.percent !== 100) {
                document.getElementById('pickfile').innerHTML = "<span>Uploading: <pre id='timer' class='bash'>" + String(file.percent).padStart(2, '0') + "%</pre></span>";
            } else {
                setInterval(function(){ seconds += 1; document.getElementById('pickfile').innerHTML = "Restoring: <pre id='timer' class='bash'>"+new Date(seconds * 1000).toISOString().substring(14, 19)+"</pre>"; }, 1000);
            }
        },

        FileUploaded: function(up, file, info) {
            // Called when file has finished uploading
            console.log('[FileUploaded] File:', file, "Info:", info);
            const xhttp = new XMLHttpRequest();
            xhttp.onload = function() {
                if(this.responseText.length > 0) {
                    document.body.innerHTML=this.responseText;
                }
            };
            xhttp.open("POST", "views.php?submit=<?php echo "$restore"; ?>");
            xhttp.setRequestHeader('X-CSRF-Token', window.PP_CSRF || ''); // perchpi:s21a-csrf-v1
            xhttp.send();
        },

        Error: function(up, err) {
            document.getElementById('console').appendChild(document.createTextNode("\nError #" + err.code + ": " + err.message));
        }
    }
});

uploader.init();
</script>
