<?php
// +----------------------------------------------------------------------
// | TopThink [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2015 http://www.topthink.com All rights reserved.
// +----------------------------------------------------------------------
// | Author: zhangyajun <448901948@qqlogin.com>
// +----------------------------------------------------------------------

namespace think;

use think\console\Command;
use think\console\command\Help as HelpCommand;
use think\console\Input;
use think\console\input\Argument as InputArgument;
use think\console\input\Definition as InputDefinition;
use think\console\input\Option as InputOption;
use think\console\Output;
use think\console\output\driver\Buffer;

class Console
{

    private $name;
    private $version;

    /** @var Command[] */
    private $commands = [];

    private $wantHelps = false;

    private $catchExceptions = true;
    private $autoExit        = true;
    private $definition;
    private $defaultCommand;

    private static $defaultCommands = [
        'help'              => "think\\console\\command\\Help",
        'list'              => "think\\console\\command\\Lists",
        'build'             => "think\\console\\command\\Build",
        'clear'             => "think\\console\\command\\Clear",
        'make:command'      => "think\\console\\command\\make\\Command",
        'make:controller'   => "think\\console\\command\\make\\Controller",
        'make:model'        => "think\\console\\command\\make\\Model",
        'make:middleware'   => "think\\console\\command\\make\\Middleware",
        'make:validate'     => "think\\console\\command\\make\\Validate",
        'optimize:autoload' => "think\\console\\command\\optimize\\Autoload",
        'optimize:config'   => "think\\console\\command\\optimize\\Config",
        'optimize:schema'   => "think\\console\\command\\optimize\\Schema",
        'optimize:route'    => "think\\console\\command\\optimize\\Route",
        'run'               => "think\\console\\command\\RunServer",
        'version'           => "think\\console\\command\\Version",
        'route:list'        => "think\\console\\command\\RouteList",
    ];

    /**
     * Console constructor.
     * @access public
     * @param  string     $name    名称
     * @param  string     $version 版本
     * @param null|string $user    执行用户
     */
    public function __construct($name = 'UNKNOWN', $version = 'UNKNOWN', $user = null)
    {
        $this->name    = $name;
        $this->version = $version;

        if ($user) {
            $this->setUser($user);
        }

        $this->defaultCommand = 'list';
        $this->definition     = $this->getDefaultInputDefinition();
    }

    /**
     * 设置执行用户
     * @param $user
     */
    public function setUser($user)
    {
        if (DIRECTORY_SEPARATOR == '\\') {
            return;
        }

        $user = posix_getpwnam($user);
        if ($user) {
            posix_setuid($user['uid']);
            posix_setgid($user['gid']);
        }
    }

    /**
     * 初始化 Console
     * @access public
     * @param  bool $run 是否运行 Console
     * @return int|Console
     */
    public static function init($run = true)
    {
        static $console;

        if (!$console) {
            $config  = Container::get('config')->pull('console');
            $console = new self($config['name'], $config['version'], $config['user']);

            $commands = $console->getDefinedCommands($config);

            // 添加指令集
            $console->addCommands($commands);
        }

        if ($run) {
            // 运行
            return $console->run();
        } else {
            return $console;
        }
    }

    /**
     * @access public
     * @param  array $config
     * @return array
     */
    public function getDefinedCommands(array $config = [])
    {
        $commands = self::$defaultCommands;

        if (!empty($config['auto_path']) && is_dir($config['auto_path'])) {
            // 自动加载指令类
            $files = scandir($config['auto_path']);

            if (count($files) > 2) {
                $beforeClass = get_declared_classes();

                foreach ($files as $file) {
                    if (pathinfo($file, PATHINFO_EXTENSION) == 'php') {
                        include $config['auto_path'] . $file;
                    }
                }

                $afterClass = get_declared_classes();
                $commands   = array_merge($commands, array_diff($afterClass, $beforeClass));
            }
        }

        $file = Container::get('env')->get('app_path') . 'command.php';

        if (is_file($file)) {
            $appCommands = include $file;

            if (is_array($appCommands)) {
                $commands = array_merge($commands, $appCommands);
            }
        }

        return $commands;
    }

    /**
     * @access public
     * @param  string $command
     * @param  array  $parameters
     * @param  string $driver
     * @return Output|Buffer
     */
    public static function call($command, array $parameters = [], $driver = 'buffer')
    {
        $console = self::init(false);

        array_unshift($parameters, $command);

        $input  = new Input($parameters);
        $output = new Output($driver);

        $console->setCatchExceptions(false);
        $console->find($command)->run($input, $output);

        return $output;
    }

    /**
     * 执行当前的指令
     * @access public
     * @return int
     * @throws \Exception
     * @api
     */
    public function run()
    {
        $input  = new Input();
        $output = new Output();

        $this->configureIO($input, $output);

        try {
            $exitCode = $this->doRun($input, $output);
        } catch (\Exception $e) {
            if (!$this->catchExceptions) {
                throw $e;
            }

            $output->renderException($e);

            $exitCode = $e->getCode();
            if (is_numeric($exitCode)) {
                $exitCode = (int) $exitCode;
                if (0 === $exitCode) {
                    $exitCode = 1;
                }
            } else {
                $exitCode = 1;
            }
        }

        if ($this->autoExit) {
            if ($exitCode > 255) {
                $exitCode = 255;
            }

            exit($exitCode);
        }

        return $exitCode;
    }

    /**
     * 执行指令
     * @access public
     * @param  Input  $input
     * @param  Output $output
     * @return int
     */
    public function doRun(Input $input, Output $output)
    {
        if (true === $input->hasParameterOption(['--version', '-V'])) {
            $output->writeln($this->getLongVersion());

            return 0;
        }

        $name = $this->getCommandName($input);

        if (true === $input->hasParameterOption(['--help', '-h'])) {
            if (!$name) {
                $name  = 'help';
                $input = new Input(['help']);
            } else {
                $this->wantHelps = true;
            }
        }

        if (!$name) {
            $name  = $this->defaultCommand;
            $input = new Input([$this->defaultCommand]);
        }

        $command = $this->find($name);

        $exitCode = $this->doRunCommand($command, $input, $output);

        return $exitCode;
    }

    /**
     * 设置输入参数定义
     * @access public
     * @param  InputDefinition $definition
     */
    public function setDefinition(InputDefinition $definition)
    {
        $this->definition = $definition;
    }

    /**
     * 获取输入参数定义
     * @access public
     * @return InputDefinition The InputDefinition instance
     */
    public function getDefinition()
    {
        return $this->definition;
    }

    /**
     * Gets the help message.
     * @access public
     * @return string A help message.
     */
    public function getHelp()
    {
        return $this->getLongVersion();
    }

    /**
     * 是否捕获异常
     * @access public
     * @param  bool $boolean
     * @api
     */
    public function setCatchExceptions($boolean)
    {
        $this->catchExceptions = (bool) $boolean;
    }

    /**
     * 是否自动退出
     * @access public
     * @param  bool $boolean
     * @api
     */
    public function setAutoExit($boolean)
    {
        $this->autoExit = (bool) $boolean;
    }

    /**
     * 获取名称
     * @access public
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * 设置名称
     * @access public
     * @param  string $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * 获取版本
     * @access public
     * @return string
     * @api
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * 设置版本
     * @access public
     * @param  string $version
     */
    public function setVersion($version)
    {
        $this->version = $version;
    }

    /**
     * 获取完整的版本号
     * @access public
     * @return string
     */
    public function getLongVersion()
    {
        if ('UNKNOWN' !== $this->getName() && 'UNKNOWN' !== $this->getVersion()) {
            return sprintf('<info>%s</info> version <comment>%s</comment>', $this->getName(), $this->getVersion());
        }

        return '<info>Console Tool</info>';
    }

    /**
     * 注册一个指令 （便于动态创建指令）
     * @access public
     * @param  string $name     指令名
     * @return Command
     */
    public function register($name)
    {
        return $this->add(new Command($name));
    }

    /**
     * 添加指令集
     * @access public
     * @param  array $commands
     */
    public function addCommands(array $commands)
    {
        foreach ($commands as $key => $command) {
            if (is_subclass_of($command, "\\think\\console\\Command")) {
                // 注册指令
                $this->add($command, is_numeric($key) ? '' : $key);
            }
        }
    }

    /**
     * 注册一个指令（对象）
     * @access public
     * @param  mixed    $command    指令对象或者指令类名
     * @param  string   $name       指令名 留空则自动获取
     * @return mixed
     */
    public function add($command, $name)
    {
        if ($name) {
            $this->commands[$name] = $command;
            return;
        }

        if (is_string($command)) {
            $command = new $command();
        }

        $command->setConsole($this);

        if (!$command->isEnabled()) {
            $command->setConsole(null);
            return;
        }

        if (null === $command->getDefinition()) {
            throw new \LogicException(sprintf('Command class "%s" is not correctly initialized. You probably forgot to call the parent constructor.', get_class($command)));
        }

        $this->commands[$command->getName()] = $command;

        foreach ($command->getAliases() as $alias) {
            $this->commands[$alias] = $command;
        }

        return $command;
    }

    /**
     * 获取指令
     * @access public
     * @param  string $name 指令名称
     * @return Command
     * @throws \InvalidArgumentException
     */
    public function get($name)
    {
        if (!isset($this->commands[$name])) {
            throw new \InvalidArgumentException(sprintf('The command "%s" does not exist.', $name));
        }

        $command = $this->commands[$name];

        if (is_string($command)) {
            $command = new $command();
        }

        $command->setConsole($this);

        if ($this->wantHelps) {
            $this->wantHelps = false;

            /** @var HelpCommand $helpCommand */
            $helpCommand = $this->get('help');
            $helpCommand->setCommand($command);

            return $helpCommand;
        }

        return $command;
    }

    /**
     * 某个指令是否存在
     * @access public
     * @param  string $name 指令名称
     * @return bool
     */
    public function has($name)
    {
        return isset($this->commands[$name]);
    }

    /**
     * 获取所有的命名空间
     * @access public
     * @return array
     */
    public function getNamespaces()
    {
        $namespaces = [];
        foreach ($this->commands as $name => $command) {
            if (is_string($command)) {
                $namespaces = array_merge($namespaces, $this->extractAllNamespaces($name));
            } else {
                $namespaces = array_merge($namespaces, $this->extractAllNamespaces($command->getName()));

                foreach ($command->getAliases() as $alias) {
                    $namespaces = array_merge($namespaces, $this->extractAllNamespaces($alias));
                }
            }

        }

        return array_values(array_unique(array_filter($namespaces)));
    }

    /**
     * 查找注册命名空间中的名称或缩写。
     * @access public
     * @param  string $namespace
     * @return string
     * @throws \InvalidArgumentException
     */
    public function findNamespace($namespace)
    {
        $allNamespaces = $this->getNamespaces();
        $expr          = preg_replace_callback('{([^:]+|)}', function ($matches) {
            return preg_quote($matches[1]) . '[^:]*';
        }, $namespace);
        $namespaces = preg_grep('{^' . $expr . '}', $allNamespaces);

        if (empty($namespaces)) {
            $message = sprintf('There are no commands defined in the "%s" namespace.', $namespace);

            if ($alternatives = $this->findAlternatives($namespace, $allNamespaces)) {
                if (1 == count($alternatives)) {
                    $message .= "\n\nDid you mean this?\n    ";
                } else {
                    $message .= "\n\nDid you mean one of these?\n    ";
                }

                $message .= implode("\n    ", $alternatives);
            }

            throw new \InvalidArgumentException($message);
        }

        $exact = in_array($namespace, $namespaces, true);
        if (count($namespaces) > 1 && !$exact) {
            throw new \InvalidArgumentException(sprintf('The namespace "%s" is ambiguous (%s).', $namespace, $this->getAbbreviationSuggestions(array_values($namespaces))));
        }

        return $exact ? $namespace : reset($namespaces);
    }

    /**
     * 查找指令
     * @access public
     * @param  string $name 名称或者别名
     * @return Command
     * @throws \InvalidArgumentException
     */
    public function find($name)
    {
        $allCommands = array_keys($this->commands);

        $expr = preg_replace_callback('{([^:]+|)}', function ($matches) {
            return preg_quote($matches[1]) . '[^:]*';
        }, $name);

        $commands = preg_grep('{^' . $expr . '}', $allCommands);

        if (empty($commands) || count(preg_grep('{^' . $expr . '$}', $commands)) < 1) {
            if (false !== $pos = strrpos($name, ':')) {
                $this->findNamespace(substr($name, 0, $pos));
            }

            $message = sprintf('Command "%s" is not defined.', $name);

            if ($alternatives = $this->findAlternatives($name, $allCommands)) {
                if (1 == count($alternatives)) {
                    $message .= "\n\nDid you mean this?\n    ";
                } else {
                    $message .= "\n\nDid you mean one of these?\n    ";
                }
                $message .= implode("\n    ", $alternatives);
            }

            throw new \InvalidArgumentException($message);
        }

        $exact = in_array($name, $commands, true);
        if (count($commands) > 1 && !$exact) {
            $suggestions = $this->getAbbreviationSuggestions(array_values($commands));

            throw new \InvalidArgumentException(sprintf('Command "%s" is ambiguous (%s).', $name, $suggestions));
        }

        return $this->get($exact ? $name : reset($commands));
    }

    /**
     * 获取所有的指令
     * @access public
     * @param  string $namespace 命名空间
     * @return Command[]
     * @api
     */
    public function all($namespace = null)
    {
        if (null === $namespace) {
            return $this->commands;
        }

        $commands = [];
        foreach ($this->commands as $name => $command) {
            if ($this->extractNamespace($name, substr_count($namespace, ':') + 1) === $namespace) {
                $commands[$name] = $command;
            }
        }

        return $commands;
    }

    /**
     * 获取可能的指令名
     * @access public
     * @param  array $names
     * @return array
     */
    public static function getAbbreviations($names)
    {
        $abbrevs = [];
        foreach ($names as $name) {
            for ($len = strlen($name); $len > 0; --$len) {
                $abbrev             = substr($name, 0, $len);
                $abbrevs[$abbrev][] = $name;
            }
        }

        return $abbrevs;
    }

    /**
     * 配置基于用户的参数和选项的输入和输出实例。
     * @access protected
     * @param  Input  $input  输入实例
     * @param  Output $output 输出实例
     */
    protected function configureIO(Input $input, Output $output)
    {
        if (true === $input->hasParameterOption(['--ansi'])) {
            $output->setDecorated(true);
        } elseif (true === $input->hasParameterOption(['--no-ansi'])) {
            $output->setDecorated(false);
        }

        if (true === $input->hasParameterOption(['--no-interaction', '-n'])) {
            $input->setInteractive(false);
        }

        if (true === $input->hasParameterOption(['--quiet', '-q'])) {
            $output->setVerbosity(Output::VERBOSITY_QUIET);
        } else {
            if ($input->hasParameterOption('-vvv') || $input->hasParameterOption('--verbose=3') || $input->getParameterOption('--verbose') === 3) {
                $output->setVerbosity(Output::VERBOSITY_DEBUG);
            } elseif ($input->hasParameterOption('-vv') || $input->hasParameterOption('--verbose=2') || $input->getParameterOption('--verbose') === 2) {
                $output->setVerbosity(Output::VERBOSITY_VERY_VERBOSE);
            } elseif ($input->hasParameterOption('-v') || $input->hasParameterOption('--verbose=1') || $input->hasParameterOption('--verbose') || $input->getParameterOption('--verbose')) {
                $output->setVerbosity(Output::VERBOSITY_VERBOSE);
            }
        }
    }

    /**
     * 执行指令
     * @access protected
     * @param  Command $command 指令实例
     * @param  Input   $input   输入实例
     * @param  Output  $output  输出实例
     * @return int
     * @throws \Exception
     */
    protected function doRunCommand(Command $command, Input $input, Output $output)
    {
        return $command->run($input, $output);
    }

    /**
     * 获取指令的基础名称
     * @access protected
     * @param  Input $input
     * @return string
     */
    protected function getCommandName(Input $input)
    {
        return $input->getFirstArgument();
    }

    /**
     * 获取默认输入定义
     * @access protected
     * @return InputDefinition
     */
    protected function getDefaultInputDefinition()
    {
        return new InputDefinition([
            new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),
            new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Display this help message'),
            new InputOption('--version', '-V', InputOption::VALUE_NONE, 'Display this console version'),
            new InputOption('--quiet', '-q', InputOption::VALUE_NONE, 'Do not output any message'),
            new InputOption('--verbose', '-v|vv|vvv', InputOption::VALUE_NONE, 'Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug'),
            new InputOption('--ansi', '', InputOption::VALUE_NONE, 'Force ANSI output'),
            new InputOption('--no-ansi', '', InputOption::VALUE_NONE, 'Disable ANSI output'),
            new InputOption('--no-interaction', '-n', InputOption::VALUE_NONE, 'Do not ask any interactive question'),
        ]);
    }

    public static function addDefaultCommands(array $classnames)
    {
        self::$defaultCommands = array_merge(self::$defaultCommands, $classnames);
    }

    /**
     * 获取可能的建议
     * @access private
     * @param  array $abbrevs
     * @return string
     */
    private function getAbbreviationSuggestions($abbrevs)
    {
        return sprintf('%s, %s%s', $abbrevs[0], $abbrevs[1], count($abbrevs) > 2 ? sprintf(' and %d more', count($abbrevs) - 2) : '');
    }

    /**
     * 返回命名空间部分
     * @access public
     * @param  string $name  指令
     * @param  string $limit 部分的命名空间的最大数量
     * @return string
     */
    public function extractNamespace($name, $limit = null)
    {
        $parts = explode(':', $name);
        array_pop($parts);

        return implode(':', null === $limit ? $parts : array_slice($parts, 0, $limit));
    }

    /**
     * 查找可替代的建议
     * @access private
     * @param  string             $name
     * @param  array|\Traversable $collection
     * @return array
     */
    private function findAlternatives($name, $collection)
    {
        $threshold    = 1e3;
        $alternatives = [];

        $collectionParts = [];
        foreach ($collection as $item) {
            $collectionParts[$item] = explode(':', $item);
        }

        foreach (explode(':', $name) as $i => $subname) {
            foreach ($collectionParts as $collectionName => $parts) {
                $exists = isset($alternatives[$collectionName]);
                if (!isset($parts[$i]) && $exists) {
                    $alternatives[$collectionName] += $threshold;
                    continue;
                } elseif (!isset($parts[$i])) {
                    continue;
                }

                $lev = levenshtein($subname, $parts[$i]);
                if ($lev <= strlen($subname) / 3 || '' !== $subname && false !== strpos($parts[$i], $subname)) {
                    $alternatives[$collectionName] = $exists ? $alternatives[$collectionName] + $lev : $lev;
                } elseif ($exists) {
                    $alternatives[$collectionName] += $threshold;
                }
            }
        }

        foreach ($collection as $item) {
            $lev = levenshtein($name, $item);
            if ($lev <= strlen($name) / 3 || false !== strpos($item, $name)) {
                $alternatives[$item] = isset($alternatives[$item]) ? $alternatives[$item] - $lev : $lev;
            }
        }

        $alternatives = array_filter($alternatives, function ($lev) use ($threshold) {
            return $lev < 2 * $threshold;
        });
        asort($alternatives);

        return array_keys($alternatives);
    }

    /**
     * 设置默认的指令
     * @access public
     * @param  string $commandName The Command name
     */
    public function setDefaultCommand($commandName)
    {
        $this->defaultCommand = $commandName;
    }

    /**
     * 返回所有的命名空间
     * @access private
     * @param  string $name
     * @return array
     */
    private function extractAllNamespaces($name)
    {
        $parts      = explode(':', $name, -1);
        $namespaces = [];

        foreach ($parts as $part) {
            if (count($namespaces)) {
                $namespaces[] = end($namespaces) . ':' . $part;
            } else {
                $namespaces[] = $part;
            }
        }

        return $namespaces;
    }

    public function __debugInfo()
    {
        $data = get_object_vars($this);
        unset($data['commands'], $data['definition']);

        return $data;
    }
}
