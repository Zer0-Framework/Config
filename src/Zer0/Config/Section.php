<?php

namespace Zer0\Config;

use Zer0\Config\Exceptions\BadFile;
use Zer0\Config\Exceptions\UnableToReadConfigFile;
use Zer0\Config\Exceptions\YamlParseError;
use Zer0\Config\Interfaces\ConfigInterface;

/**
 * Class Section
 *
 * @package Zer0\Config
 */
class Section implements ConfigInterface
{

    /**
     * @var ConfigInterface
     */
    protected $parent;

    /**
     * @var mixed
     */
    protected $data;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var array
     */
    protected $sections = [];

    /**
     * @var string
     */
    protected $env;

    /**
     * @var array
     */
    protected $loadedFiles;

    /**
     * Section constructor.
     *
     * @param ConfigInterface $parent
     * @param string $name
     * @param string $env
     * @param array &         $loadedFiles
     *
     * @throws UnableToReadConfigFile
     */
    public function __construct(ConfigInterface $parent, string $name, string $env, &$loadedFiles)
    {
        $this->parent = $parent;
        $this->name = $name;
        $this->env = $env;
        $this->loadedFiles =& $loadedFiles;

        $combined = [];

        $pattern = '{' . implode(',', $this->getPath()) . '}' . '/{*-,}{default,' . $this->env . '}.y{a,}ml';
        $files = glob($pattern, GLOB_BRACE);
        foreach ($files as $file) {
            if ($loadedFiles !== null) {
                $loadedFiles[] = $file;
            }
            try {
                $data = \yaml_parse_file(
                    $file,
                    0,
                    $ndocs,
                    [
                        '!env' => [$this, 'callbackEnv'],
                        '!path' => [$this, 'callbackPath'],
                        '!map' => [$this, 'callbackMap'],
                    ]
                );
            } catch (\ErrorException $e) {
                throw YamlParseError::deriveFromError($e);
            }
            if ($data === false) {
                throw new UnableToReadConfigFile(substr($file, strlen(ZERO_ROOT)));
            }
            if (is_array($data)) {
                $combined = array_merge($combined, $data);
            }
        }

        $this->data = $combined;
    }

    /**
     * @return mixed
     */
    public function root(): Config
    {
        return $this->parent->root();
    }

    /**
     * @param string $str
     *
     * @return mixed
     */
    public function callbackMap(string $str)
    {
        $args = preg_split('~\s+~', $str, 2);
        $el = $this->root();
        foreach (explode('/', $args[0]) as $name) {
            if (is_array($el)) {
                $el = $el[$name];
            } elseif (is_object($el)) {
                $el = $el->{$name};
            } else {
                throw new \RuntimeException('!map: cannot reach path ' . $args[0] . ' because ' . $name . ' is not inside an array/object');
            }
        }
        $ret = [];
        foreach ($el as $item) {
            try {
                $ret[] = \yaml_parse(
                    $args[1],
                    0,
                    $ndocs,
                    [
                        '!item' => function($str) use ($item) {
                            return $item;
                        },
                        '!item[host]' => function() use ($item) {
                            return explode(':' , $item)[0];
                        },
                        '!item[port]' => function($default) use ($item) {
                             return (int) (explode(':' , $item)[1] ?? $default);
                        },
                    ]
                );
            } catch (\ErrorException $e) {
                throw YamlParseError::deriveFromError($e);
            }
        }
        return $ret;
    }

    /**
     * @param string $str
     *
     * @return mixed
     */
    public function callbackEnv(string $str)
    {
        foreach (explode('||', $str) as $alt) {
            $alt = trim($alt);
            if (ctype_alpha(substr($alt, 0, 1))) {
                $value = $_ENV[$alt] ?? null;
            } else {
                $value = yaml_parse($alt);
            }
            if ($value !== null) {
                return $value;
            }
        }
    }

    /**
     * @param string $str
     *
     * @return string
     */
    public function callbackPath(string $str)
    {
        return ZERO_ROOT . '/' . ltrim($str, '/');
    }

    /**
     * @return array
     */
    public function getPath(): array
    {
        $path = $this->parent->getPath();
        foreach ($path as &$item) {
            $item .= '/' . $this->getName();
        }

        return $path;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array
     */
    public function sectionsList(): array
    {
        return array_unique(
            array_map(
                'basename',
                array_map(
                    'dirname',
                    glob(
                        '{' . implode(',', $this->getPath()) . '}' . '/*/{*-,}{default,' . $this->env . '}.y{a,}ml',
                        GLOB_BRACE
                    )
                )
            )
        );
    }

    /**
     * @return int|bool
     */
    public function lastModified()
    {
        return filemtime($this->path);
    }

    /**
     * @param string $name
     *
     * @return mixed|null|Section
     * @throws UnableToReadConfigFile
     */
    public function __get(string $name)
    {
        $F = substr($name, 0, 1);
        if (ctype_alpha($F) && strtoupper($F) === $F) {
            return $this->sections[$name] ?? ($this->sections[$name] = new self(
                    $this,
                    $name,
                    $this->env,
                    $this->loadedFiles
                ));
        }

        return $this->data[$name] ?? null;
    }

    /**
     * @param string $name
     * @param        $value
     */
    public function __set(string $name, $value): void
    {
        $this->data[$name] = $value;
    }

    /**
     * @param string $name
     *
     * @return mixed|null
     */
    public function getValue(string $name)
    {
        return $this->data[$name] ?? null;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @return bool
     */
    public function exists(): bool
    {
        return count($this->data) > 0;
    }
}
