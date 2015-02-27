<?php namespace App\Console\Commands;

use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Input\InputArgument;
use SplStack;

class WebRip extends Command {

	/**
	 * @var SplStack
	 */
	protected $stack;

	/**
	 * @var array
	 */
	protected $ready;
	/**
	 * @var Client
	 */
	protected $client;

	/**
	 * @var string
	 */
	protected $path;

	/**
	 * @var Filesystem
	 */
	protected $filesystem;

	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $name = 'web:rip';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Command description.';

	/**
	 * Create a new command instance.
	 */
	public function __construct()
	{
		parent::__construct();
		$this->stack = new SplStack();
		$this->ready = [];
	}

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function fire(Filesystem $filesystem)
	{
		$url = $this->argument('url');
		$this->filesystem = $filesystem;
		$this->path = public_path('rip/' . $this->argument('path'));
		$this->client = new Client([ 'base_url' => $url ]);

		$this->stack->push('index.html');

		while ($this->stack->count())
		{
			$this->rip();
		}

	}

	/**
	 * @return  bool
	 */
	protected function rip()
	{
		$url = $this->stack->pop();
		if (in_array($url, $this->ready)) return;
		$url = explode('.', $url);
		$ext = end($url);
		$url = implode('.', $url);
		$path = explode('/', $url);
		$filename = array_pop($path);
		$path = implode('/', $path);
		try {
			$response = $this->client->get($url);
			array_unshift($this->ready, $url);
			$body = $response->getBody();
			$this->info('Loaded '.$url.' writing to '.$path);
			if (!$this->filesystem->isDirectory($this->path.'/'.$path))
			{
				$this->filesystem->makeDirectory($this->path.'/'.$path, 0755, true);
			}
			$this->filesystem->put($this->path.'/'.$path.'/'.$filename, $body);
			if ($ext == 'html' || $ext = 'htm' || $ext == 'php')
			{
				$this->extractFromHtml($body, $path);
			}
			elseif ($ext == 'css')
			{
				$this->extractFromCss($body, $path);
			}
		}
		catch (\Exception $e)
		{
			$this->error($e->getMessage());
			array_unshift($this->ready, $url);
			return false;
		}
	}

	/**
	 * @param  string  $body
	 * @param  string  $path
	 */
	protected function extractFromHtml($body, $path)
	{
		preg_match_all('/(src|href)=\"([^\"]+)\"/', $body, $matches);
		$this->processLinks($matches[2], $path);
	}

	/**
	 * @param  string  $body
	 * @param  string  $path
	 */
	protected function extractFromCss($body, $path)
	{
		preg_match_all('/url\(\'([^\']+)\'\)/', $body, $matches);
		$this->processLinks($matches[1], $path);
	}

	/**
	 * @param  array  $links
	 * @param  string  $path
	 */
	protected function processLinks($links, $path)
	{
		$items = [];
		array_walk($links, function($item) use (&$items, $path)
		{
			if (strpos($item, '#') === 0) return false;
			if (strpos($item, 'http') === 0) return false;
			$item = explode('?', explode('#', $item)[0])[0];
			if (strpos($item, '/') === 0)
			{
				$items[] = $this->getCleanUrl($item);
			}
			else
			{
				$items[] = $this->getCleanUrl($item, $path);
			}
		});

		$items = array_filter($items, function($item)
		{
			return !in_array($item, $this->ready);
		});

		foreach ($items as $url)
		{
			$this->stack->push($url);
		}
	}

	/**
	 * @param  string  $url
	 * @param  string  $path
	 *
	 * @return  bool|string
	 */
	protected function getCleanUrl($url, $path = null)
	{
		$url = explode('/', $url);
		if ($path)
		{
			$final = explode('/', $path);
			$j = count($final) - 1;
		}
		else
		{
			$final = [];
			$j = 0;
		}
		foreach ($url as $part)
		{

			if ($part == '.' || $part == '') continue;
			elseif ($part == '..')
			{
				if ($j <= 0) return false;
				$j--;
				array_pop($final);
				continue;
			}
			else
			{
				$final[] = $part;
			}
		}
		return implode('/', $final);
	}

	/**
	 * Get the console command arguments.
	 *
	 * @return array
	 */
	protected function getArguments()
	{
		return [
			['url', InputArgument::REQUIRED, 'Url to rip.'],
			['path', InputArgument::REQUIRED, 'Storage path.']
		];
	}

	/**
	 * Get the console command options.
	 *
	 * @return array
	 */
	protected function getOptions()
	{
		return [
			//['example', null, InputOption::VALUE_OPTIONAL, 'An example option.', null],
		];
	}

}
