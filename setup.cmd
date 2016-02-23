schtasks /delete /tn "VoteHereBot"
schtasks /create /tn "VoteHereBot" /tr "C:\VoteHereBot\php\php.exe C:\VoteHereBot\VoteHereBot\main.php" /sc minute /mo 5
pause