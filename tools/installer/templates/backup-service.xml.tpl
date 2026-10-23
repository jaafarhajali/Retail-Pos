<service>
  <id>{{PRODUCT}}Backup</id>
  <name>{{DISPLAY}} Backup</name>
  <description>Makes the daily backup of {{DISPLAY}} at the time set on its Backups page, and retries a Google Drive upload that failed.</description>
  <executable>{{ROOT}}\php\php.exe</executable>
  <arguments>"{{ROOT}}\app\bin\backup-service.php"</arguments>
  <workingdirectory>{{ROOT}}\app</workingdirectory>
  <startmode>Automatic</startmode>
  <depend>{{PRODUCT}}DB</depend>
  <onfailure action="restart" delay="60 sec" />
  <log mode="roll-by-size">
    <sizeThreshold>1024</sizeThreshold>
    <keepFiles>3</keepFiles>
  </log>
  <prestop>cmd /c echo stop &gt; "{{ROOT}}\app\storage\backup-service.stop"</prestop>
  <stoptimeout>70 sec</stoptimeout>
</service>
