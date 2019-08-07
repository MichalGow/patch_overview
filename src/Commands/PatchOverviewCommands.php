<?php
namespace Drupal\patch_overview\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Drush\Exec\ExecTrait;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Provides Patch show Drush command, which prints state of patches applied by cweagans/composer-patches
 * Composer plugin.
 *
 * The code was inspired by
 *
 * https://github.com/drush-ops/drush/tree/master/examples/Commands
 * https://bitbucket.org/davereid/drush-patchfile/src/master/
 *
 * Class PatchOverviewCommands
 * @package Drush\Commands
 */

class PatchOverviewCommands extends DrushCommands
{
  protected $drupalRoot;

  /**
   * PatchOverviewCommands constructor.
   */
  public function __construct()
  {
    $this->drupalRoot = Drush::bootstrapManager()->getRoot();
    parent::__construct();
  }

  /**
   * Show applied patches.
   *
   * @command patch:show
   * @aliases patchs
   * @usage drush patchs
   *   Show patches applied by cweagans/composer-patches Composer plugin.
   *
   * @param array $options
   * @return mixed
   * @throws \Exception
   */
  public function show($options = ['format' => 'table'])
  {
    $patches = $this->getComposerPatches();
    $rows = [];
    foreach ($patches as $patch) {
      $rows[] = [
        'Module' => $patch['module'],
        'Source' => $patch['source'],
        'Patch applied' => $patch['status']
      ];
    }
    return new RowsOfFields($rows);
  }

  /**
   * Gets list of patches.
   *
   * @return array
   * @throws \Exception
   */
  protected function getComposerPatches() {
    $content = [];
    $package_paths = [];
    $patches_output = [];

    // Obtain content of Composer files.
    foreach (['json', 'lock'] as $file_type) {
      $content[$file_type] = $this->getComposerContent($file_type);
    }

    $patches = &$content['json']['extra']['patches'];

    // Get package paths.
    foreach ($content['json']['extra']['installer-paths'] as $path => $types) {
      foreach ($types as $type) {
        $type = explode(':',$type);
        $type = end($type);
        $package_paths[$type] = $path;
      }
    }

    // Add package and patch paths to patches.
    foreach ($content['lock']['packages'] as $package) {
      if (array_key_exists ($package['name'], $patches)) {
        $name = explode('/', $package['name']);
        $name = end($name);
        if (array_key_exists ($package['type'], $package_paths)) {
          $package_path = $this->drupalRoot . '/../' . strtr($package_paths[$package['type']], array('{$name}' => $name));
        } else {
          $package_path = $this->drupalRoot . "/../vendor/$name";
        }
        foreach ($patches[$package['name']] as $patch_source => $patch) {
          $patch_url = $this->patchUrl($patch);
          $patches_output[] = [
            'module' => $package['name'],
            'package_path' => $package_path,
            'source' => $patch_source,
            'patch' => $patch_url,
            'status' => $this->patchStatus($package_path, $patch_url),
          ];
        }
      }
    }
    return $patches_output;
  }

  /**
   * Get JSON data from composer file.
   *
   * @param $file_type
   * @return mixed
   */
  protected function getComposerContent($file_type) {
    $path = $this->drupalRoot . "/../composer.$file_type";
    $contents = file_get_contents($path);
    return json_decode($contents, true);
  }

  /**
   * Downloads a file.
   *
   * Optionally uses user authentication, using either wget or curl, as available.
   *
   *
   * @param $url
   * @param bool $user
   * @param bool $password
   * @param bool $destination
   * @param bool $overwrite
   * @return bool|string
   * @throws \Exception
   */
  protected function downloadFile($url, $user = false, $password = false, $destination = false, $overwrite = true)
  {
    static $use_wget;
    if ($use_wget === null) {
      $use_wget = ExecTrait::programExists('wget');
    }
    $destination_tmp = drush_tempnam('download_file');
    if ($use_wget) {
      $args = ['wget', '-q', '--timeout=30'];
      if ($user && $password) {
        $args = array_merge($args, ["--user=$user", "--password=$password", '-O', $destination_tmp, $url]);
      } else {
        $args = array_merge($args, ['-O', $destination_tmp, $url]);
      }
    } else {
      $args = ['curl', '-s', '-L', '--connect-timeout 30'];
      if ($user && $password) {
        $args = array_merge($args, ['--user', "$user:$password", '-o', $destination_tmp, $url]);
      } else {
        $args = array_merge($args, ['-o', $destination_tmp, $url]);
      }
    }
    $process = Drush::process($args);
    $process->mustRun();
    if (!Drush::simulate()) {
      if (!drush_file_not_empty($destination_tmp) && $file = @file_get_contents($url)) {
        @file_put_contents($destination_tmp, $file);
      }
      if (!drush_file_not_empty($destination_tmp)) {
        // Download failed.
        throw new \Exception(dt("The URL !url could not be downloaded.", ['!url' => $url]));
      }
    }
    if ($destination) {
      $fs = new Filesystem();
      $fs->rename($destination_tmp, $destination, $overwrite);
      return $destination;
    }
    return $destination_tmp;
  }

  /**
   * Return absolute path to patch file.
   *
   * @param $patch
   * @return bool|string
   * @throws \Exception
   */
  protected function patchUrl($patch) {
    if (filter_var($patch, FILTER_VALIDATE_URL)) {
      return $this->downloadFile($patch);
    } else {
      return $this->drupalRoot . "/$patch";
    }
  }

  /**
   * Check patch status.
   *
   * @param $package_path
   * @param $patch_url
   * @return string
   */
  protected function patchStatus($package_path, $patch_url) {
    $patch_levels = ['-p1', '-p0'];

    foreach ($patch_levels as $patch_level) {
      $args = ['patch', $patch_level, '-R', '--dry-run', "< $patch_url"];
      $command_line = implode(" ", $args); // Less than symbol breaks process array commandLine
      $process = Drush::process($command_line, $package_path);
      $exit_code = $process->run();
      if (!$exit_code) {
        return 'Applied';
      }

      $args = ['patch', $patch_level, '--dry-run', "< $patch_url"];
      $command_line = implode(" ", $args); // Less than symbol breaks process array commandLine
      $process = Drush::process($command_line, $package_path);
      $exit_code = $process->run();
      if (!$exit_code) {
        return 'Not applied';
      }
    }

    return 'Unsure';
  }
}
