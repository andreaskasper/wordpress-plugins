<?php header( 'Location: /loop.php?n=' . ( (int) ( $_GET['n'] ?? 0 ) + 1 ), true, 302 );
