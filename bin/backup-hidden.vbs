' backup-hidden.vbs - runs "<php> <script> --auto" with no console window; what the daily Windows task calls
' (schtasks opens a black window for a console program; wscript //B with window style 0 does not).
If WScript.Arguments.Count < 2 Then WScript.Quit 2
CreateObject("WScript.Shell").Run """" & WScript.Arguments(0) & """ """ & WScript.Arguments(1) & """ --auto", 0, False
