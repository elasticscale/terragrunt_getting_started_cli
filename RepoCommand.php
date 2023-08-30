<?php

class RepoCommand extends Ahc\Cli\Input\Command
{
    // includes trailing slash
    private $baseFolder = './output/';

    public function __construct()
    {
        parent::__construct('', 'This creates the GIT repos for your Terragrunt project based on the elasticscale repos');
    }

    private function dirIsEmpty($dir)
    {
        $handle = opendir($dir);
        while (false !== ($entry = readdir($handle))) {
            if ($entry != "." && $entry != ".." && $entry != ".DS_Store") {
                closedir($handle);
                return false;
            }
        }
        closedir($handle);
        return true;
    }

    public function interact(Ahc\Cli\IO\Interactor $io): void
    {
        if (!$this->dirIsEmpty($this->baseFolder)) {
            throw new Exception('The output directory (volume) must be empty (including any hidden files)');
        }

        $io = $this->app()->io();

        $io->write("Let us start off with some base information", true);

        if (!$this->region) {
            $io->write("Choose the region to deploy your infrastructure too", true);
            $io->write("For example: eu-west-1", true);
            $this->set('region', $io->prompt('Enter your AWS region', 'eu-west-1'));
        }

        if (!$this->vcsType) {
            $io->write("Choose the VCS type for the Codestar connection", true);
            $this->set('vcsType', $io->choice('Choose your VCS system', [
                'GitHub',
                'GitHubEnterpriseServer',
                'Bitbucket',
                'GitLab'
            ], 'GitHub'));
        }

        $io->write("Now we need some information about your repositories", true);

        if (!$this->gitRepoPath) {
            $io->write("Enter the path to your GIT repo, mostly this is some format like organisation/repo_infrastructure", true);
            $io->write("For example: elasticscale/acmesystems_infrastructure", true);
            $this->set('gitRepoPath', $io->prompt('Enter your infrastructure repo path'));
        }

        if (!$this->gitRepoModulesPath) {
            $io->write("Enter the path to your GIT modules repo, mostly this is some format like organisation/repo_infrastructure_modules", true);
            $io->write("For example: elasticscale/acmesystems_infrastructure_modules", true);
            $this->set('gitRepoModulesPath', $io->prompt('Enter your infrastructure modules repo path', $this->gitRepoPath . '_modules'));
        }

        if (!$this->gitCloneUrl) {
            $io->write("Enter git clone url, this is the full URL to the modules repo including protocol and .git", true);
            $io->write("For example: git::ssh://git@github.com/elasticscale/acmesystems_infrastructure_modules.git", true);
            $this->set('gitCloneUrl', $io->prompt('Enter your GIT clone url', "git::ssh://git@github.com/" . $this->gitRepoModulesPath . ".git"));
        }

        if (!$this->prefix) {
            $prefixValidator = function ($value) {
                if (!preg_match('/^[a-z\-]+$/', $value)) {
                    throw new \InvalidArgumentException('Prefix must be lowercase and dashes');
                }
                if (substr($value, -1) == '-') {
                    throw new \InvalidArgumentException('Prefix must not end with a dash');
                }
                if (substr($value, 0, 1) == '-') {
                    throw new \InvalidArgumentException('Prefix must not start with a dash');
                }
                if (\strlen($value) > 16) {
                    throw new \InvalidArgumentException('Prefix must be less than 16 chars');
                }
                return $value;
            };
            $io->write("Enter your prefix, this must be globally unique (potentially with dashes and not longer than 16 chars)", true);
            $io->write("For example: acmesystems", true);
            $this->set('prefix', $io->prompt('Enter your prefix', null, $prefixValidator));
        }

        $accountIdValidator = function ($value) {
            if (!preg_match('/^\d{12}$/', $value)) {
                throw new \InvalidArgumentException('Account ID must be 12 digits');
            }
            return $value;
        };

        $io->write("Now we will ask you for your account IDs you have created in AWS", true);
        if (!$this->securityAccountId) {
            $this->set('securityAccountId', $io->prompt('Enter your security account ID', null, $accountIdValidator));
        }
        if (!$this->infrastructureAccountId) {
            $this->set('infrastructureAccountId', $io->prompt('Enter your infrastructure account ID', null, $accountIdValidator));
        }
        if (!$this->stagingAccountId) {
            $this->set('stagingAccountId', $io->prompt('Enter your staging account ID', null, $accountIdValidator));
        }
        if (!$this->productionAccountId) {
            $this->set('productionAccountId', $io->prompt('Enter your production account ID', null, $accountIdValidator));
        }

        $io->write("Finally we will need your Docker Hub username and public access token to pull containers for the ECR clone module", true);
        $io->write("Initially these will be stored in the repository, you can rotate them later on (see the blog post under FAQ)", true);
        $io->write("The public access token of Docker can only be used to pull public repositories and is read only", true);

        if (!$this->dockerhubUsername) {
            $this->set('dockerhubUsername', $io->prompt('Enter your Docker Hub username', null));
        }
        if (!$this->dockerhubToken) {
            $dockerTokenValidator = function ($value) {
                if (!preg_match('/^dckr_pat_/', $value)) {
                    throw new \InvalidArgumentException('Docker Hub token must start with dckr_pat_');
                }
                return $value;
            };
            $this->set('dockerhubToken', $io->prompt('Enter your Docker Hub Public Repo (Read Only) token (starts with dckr_pat_)', null, $dockerTokenValidator));
        }

        $io->write("This is your input, is this correct?", true);

        $io->table([
            ['field' => 'region', 'input' => $this->region],
            ['field' => 'vcsType', 'input' => $this->vcsType],
            ['field' => 'gitRepoPath', 'input' => $this->gitRepoPath],
            ['field' => 'gitRepoModulesPath', 'input' => $this->gitRepoModulesPath],
            ['field' => 'gitCloneUrl', 'input' => $this->gitCloneUrl],
            ['field' => 'prefix', 'input' => $this->prefix],
            ['field' => 'securityAccountId', 'input' => $this->securityAccountId],
            ['field' => 'infrastructureAccountId', 'input' => $this->infrastructureAccountId],
            ['field' => 'stagingAccountId', 'input' => $this->stagingAccountId],
            ['field' => 'productionAccountId', 'input' => $this->productionAccountId],
            ['field' => 'dockerhubUsername', 'input' => $this->dockerhubUsername],
            ['field' => 'dockerhubToken', 'input' => $this->dockerhubToken],
        ]);

        $confirm = $io->confirm('Are you happy with these settings? If you choose y the GIT repos will be generated for you in the volume specified', 'n');

        if (!$confirm) {
            throw new Exception("User aborted, please run script again with the correct inputs");
        }
    }

    public function execute(
        $region,
        $vcsType,
        $gitRepoPath,
        $gitRepoModulesPath,
        $gitCloneUrl,
        $securityAccountId,
        $infrastructureAccountId,
        $stagingAccountId,
        $productionAccountId,
        $dockerhubUsername,
        $dockerhubToken
    ) {
        // download and unzip
        $this->download("infrastructure.zip", "https://github.com/elasticscale/elasticscale_infrastructure/archive/refs/heads/main.zip");
        $this->unzip("infrastructure.zip", "elasticscale_infrastructure-main");
        $this->download("infrastructure_modules.zip", "https://github.com/elasticscale/elasticscale_infrastructure_modules/archive/refs/heads/main.zip");
        $this->unzip("infrastructure_modules.zip", "elasticscale_infrastructure_modules-main");
        // init git
        $this->initGit("infrastructure");
        $this->initGit("infrastructure_modules");
        // cleanup license and readme
        $this->removeLicenseAndReadme("infrastructure");
        $this->removeLicenseAndReadme("infrastructure_modules");
        // replaces for infrastructure
        $this->replaceInFiles("replacing region", 'region: eu-west-1', 'region: ' . $region, "infrastructure");
        $this->replaceInFiles("replacing vcs type", 'provider_type           = "GitHub"', 'provider_type           = "'.$vcsType.'"', "infrastructure");
        $this->replaceInFiles("replacing docker hub username", 'docker_hub_username     = "usernamegithub"', 'docker_hub_username     = "'.$dockerhubUsername.'"', "infrastructure");
        $this->replaceInFiles("replacing docker hub public repo pull token", 'docker_hub_access_token = "dckr_pat_access_token"', 'docker_hub_access_token = "'.$dockerhubToken.'"', "infrastructure");
        $this->replaceInFiles("replacing repo", 'repository_name_infrastructure = "elasticscale/elasticscale_infrastructure"', 'repository_name_infrastructure = "'.$gitRepoPath.'"', "infrastructure");
        $this->replaceInFiles("replacing repo modules", 'repository_name_modules        = "elasticscale/elasticscale_infrastructure_modules"', 'repository_name_modules        = "'.$gitRepoModulesPath.'"', "infrastructure");
        $this->replaceInFiles("replacing full modules url", 'full_modules_url               = "git::ssh://git@github.com/elasticscale/elasticscale_infrastructure_modules.git"', 'full_modules_url               = "'.$gitCloneUrl.'"', "infrastructure");
        $this->replaceInFiles("replacing module path", "git::ssh://git@github.com/elasticscale/elasticscale_infrastructure_modules.git", $gitCloneUrl, "infrastructure");
        $this->replaceInFiles("replacing prefix", "prefix: elasticscale", "prefix: $this->prefix", "infrastructure");
        $this->replaceInFiles("replacing security account ID", "689298222225", $securityAccountId, "infrastructure");
        $this->replaceInFiles("replacing infra account ID", "136431940157", $infrastructureAccountId, "infrastructure");
        $this->replaceInFiles("replacing staging account ID", "257804771987", $stagingAccountId, "infrastructure");
        $this->replaceInFiles("replacing prod account ID", "540259191132", $productionAccountId, "infrastructure");
        $this->branches("infrastructure", [
            "local",
            "infra",
            "security",
            "staging",
            "prod"
        ]);
        $this->tag("infrastructure_modules", "1.0.0");

        $content = <<<EOT
region                                 = "$region"
docker_hub_username                    = "$dockerhubUsername"
docker_hub_access_token                = "$dockerhubToken"
repository_name_infrastructure         = "$gitRepoPath"
repository_name_infrastructure_modules = "$gitRepoModulesPath"
full_modules_url                       = "$gitCloneUrl"
infrastructure_account_id              = "$infrastructureAccountId"
EOT;
        $this->generateTempVarsFile("infrastructure_modules", $content);
        // rename it so the names are correct for an easy push
        rename($this->baseFolder . "infrastructure", $this->baseFolder . "{$this->prefix}_infrastructure");
        rename($this->baseFolder . "infrastructure_modules", $this->baseFolder . "{$this->prefix}_infrastructure_modules");
        // exit code
        return 0;
    }

    private function generateTempVarsFile($folder, $content)
    {
        file_put_contents($this->baseFolder . $folder . "/pipeline/temp.tfvars", $content);
        // no need to commit on account of the .gitignore
    }

    private function tag($folder, $version)
    {
        $io = $this->app()->io();
        $shell = new Ahc\Cli\Helper\Shell($command = 'cd ' . $this->baseFolder . $folder . ' && git tag ' . $version, $rawInput = null);
        $shell->execute($async = false);
        $io->comment($shell->getOutput());
    }

    private function branches($folder, $branches)
    {
        $io = $this->app()->io();
        foreach($branches as $branch) {
            $shell = new Ahc\Cli\Helper\Shell($command = 'cd ' . $this->baseFolder . $folder . ' && git branch ' . $branch, $rawInput = null);
            $shell->execute($async = false);
            $io->comment($shell->getOutput());
        }
    }

    private function rsearch($folder, $regPattern)
    {
        $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder));
        $files = array();

        /** @var SplFileInfo $file */
        foreach ($rii as $file) {
            if(!in_array($file->getExtension(), ["hcl", "yaml"])) {
                continue;
            }
            if($file->getBaseName() == ".terraform.lock.hcl") {
                continue;
            }
            $files[] = $file->getPathname();
        }
        return $files;
    }

    private function replaceInFiles($message, $find, $replace, $folder)
    {
        // replace via glob calls in files:
        $io = $this->app()->io();
        $io->write($message, $folder);
        $files = $this->rsearch($this->baseFolder . $folder, "*.*");
        foreach ($files as $file) {
            if(is_dir($file)) {
                continue;
            }
            $io->write("Replacing in $file", $folder);
            $content = file_get_contents($file);
            $content = str_replace($find, $replace, $content);
            file_put_contents($file, $content);
        }
        $this->commitGit($folder, $message);

    }

    private function removeLicenseAndReadme($folder)
    {
        $io = $this->app()->io();
        $io->write("Removing license and readme", $folder);
        unlink($this->baseFolder . $folder . "/LICENSE");
        unlink($this->baseFolder . $folder . "/README.md");
        $this->commitGit($folder, "removed license and readme");
    }

    private function initGit($folder)
    {
        $io = $this->app()->io();
        $io->write("Initializing GIT repo", $folder);
        // init
        $shell = new Ahc\Cli\Helper\Shell($command = 'cd ' . $this->baseFolder . $folder . ' && git init', $rawInput = null);
        $shell->execute($async = false);
        $io->comment($shell->getOutput());
        $this->commitGit($folder, "Initial commit");

    }

    private function commitGit($folder, $message)
    {
        // add
        $io = $this->app()->io();
        $shell = new Ahc\Cli\Helper\Shell($command = 'cd ' . $this->baseFolder . $folder . ' && git add .', $rawInput = null);
        $shell->execute($async = false);
        $io->comment($shell->getOutput());
        // commit
        $shell = new Ahc\Cli\Helper\Shell($command = 'cd ' . $this->baseFolder . $folder . ' && git commit -m "'.$message.'"', $rawInput = null);
        $shell->execute($async = false);
        $io->comment($shell->getOutput());
    }

    private function download($name, $url)
    {
        $io = $this->app()->io();
        $io->write("Downloading $name from $url", true);

        $fh = fopen($this->baseFolder . $name, "w");
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fh);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);
        curl_close($ch);
    }

    private function unzip($filename, $folder)
    {
        $io = $this->app()->io();
        $io->write("Unzipping $filename", true);

        $zip = new ZipArchive();
        $res = $zip->open($this->baseFolder . $filename);
        if ($res === true) {
            $zip->extractTo($this->baseFolder);
            $zip->close();
        }

        rename($this->baseFolder . $folder, $this->baseFolder . str_replace(".zip", "", $filename));
        unlink($this->baseFolder . $filename);
    }
}
