<?php
echo "hello, I am running inside the embedded interpreter\n";
printf("PHP_VERSION=%s PHP_SAPI=%s PHP_ZTS=%d\n", PHP_VERSION, PHP_SAPI, PHP_ZTS);
printf("parallel loaded? %s\n", extension_loaded('parallel') ? 'yes' : 'no');
