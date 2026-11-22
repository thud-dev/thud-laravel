<?php

declare(strict_types=1);

// Router for `php -S`: an ingest API that takes 3 seconds to answer.
sleep(3);
http_response_code(202);
