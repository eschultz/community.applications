<?
###############################################################
#                                                             #
# Community Applications copyright 2015-2016, Andrew Zawadzki #
#                                                             #
###############################################################

function getLineCount($directory) {
  global $lineCount, $charCount;

  $allFiles = array_diff(scandir($directory),array(".",".."));
  foreach ($allFiles as $file) {
    if (is_dir("$directory/$file")) {
      getLineCount("$directory/$file");
      continue;
    }
    $extension = pathinfo("$directory/$file",PATHINFO_EXTENSION);
    if ( $extension == "sh" || $extension == "php" || $extension == "page" ) {
      $lineCount = $lineCount + count(file("$directory/$file"));
      $charCount = $charCount + filesize("$directory/$file");
    }
  }
}

$caCredits = "
    <center><table align:'center'>
      <tr>
        <td><img src='https://github.com/Squidly271/plugin-repository/raw/master/Chode_300.gif' width='50px';height='48px'></td>
        <td><strong>Andrew Zawadzki</strong></td>
        <td>Main Development</td>
      </tr>
      <tr>
        <td></td>
        <td><strong>bonienl</strong></td>
        <td>Additional Contributions</td>
      </tr>
      <tr>
        <td><img src='http://fanart.tv/ftv_128.jpg' height='48px' width='48px'></td>
        <td><strong>Kode</strong></td>
        <td>Application Feed</td>
      </tr>
      <tr>
        <td><img src='http://i.imgur.com/VFMJTol.jpg' width='48px' height='48px'></td>
        <td><strong>Sparklyballs</strong></td>
        <td>Additional Testing</td>
      </tr>
      <tr>
        <td><img src='https://github.com/Squidly271/plugin-repository/raw/master/minion.thumb.jpg.b6e9d9eebd4588a36dd4511eee285133.jpg' width='48px' height='48px'></td>
        <td><strong>CHBMB</strong></td>
        <td>Additional Testing</td>
      </tr>
    </table></center>
    <br>
    <center><em><font size='1'>Copyright 2015-2016 Andrew Zawadzki</font></em></center>
    <center><a href='https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=7M7CBCVU732XG' target='_blank'><img src='https://www.paypalobjects.com/en_US/i/btn/btn_donateCC_LG.gif'></a></center>
    <br><center><a href='http://lime-technology.com/forum/index.php?topic=40262.0' target='_blank'>Plugin Support Thread</a></center>
  ";
  getLineCount("/usr/local/emhttp/plugins/community.applications");
  $caCredits .= "<center>$lineCount Lines of code and counting! ($charCount characters)</center>";
  $caCredits = str_replace("\n","",$caCredits);
?>