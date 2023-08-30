<?php

use Aws\Organizations\OrganizationsClient;  
use Aws\Exception\AwsException;

class AwsCommand extends Ahc\Cli\Input\Command
{
    public function __construct()
    {
        parent::__construct('aws', 'This creates a new OU with empty AWS Organizations and IAM roles');
    }

    public function interact(Ahc\Cli\IO\Interactor $io): void
    {
        $io = $this->app()->io();

        $io->write("Let us start off with some base information", true);

        // todo, inputs (plus determine roles)
        $this->set('awsAccessKeyId', $_ENV['AWS_ACCESS_KEY_ID']);
        $this->set('awsAccessKeySecret', $_ENV['AWS_SECRET_ACCESS_KEY']);
        $this->set('ouName', 'ElasticScale123');

        if (!$this->ouName) {
            $io->write("Choose the name of the new OU", true);
            $io->write("For example: Acme Systems Ltd", true);
            $this->set('ouName', $io->prompt('Enter the new OU name'));
        }

        $awsOrganizations = $this->getAwsOrganizations(); 
    
        $emailValidator = function ($value) use ($awsOrganizations) {
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email address");
            }            
            foreach($awsOrganizations as $account) {
                if ($account['Email'] == $value) {
                    throw new Exception("Email already exists in AWS Organizations for ID " . $account['Id']);
                }
            }
            return $value;
        };


        // todo
        // ["security", "local", "infra", "staging", "prod"]
        foreach(["security"] as $environment) {
            if (!$this->{$environment . "Email"}) {
                $io->write("Choose an email for the root user of the $environment account", true);
                $io->write("You can use aliasing here for example alex+$environment@elasticscale.cloud (provided your email provider supports it)", true);
                $this->set($environment . "Email", $io->prompt('Enter the email', $environment != 'security' ? str_replace('+security', '+' . $environment, $this->securityEmail) : null, $emailValidator));
            }
        } 

        $io->write("This is your input, is this correct?", true);

        $io->table([
            ['field' => 'ouName', 'input' => $this->ouName],
            ['field' => 'securityEmail', 'input' => $this->securityEmail],
            ['field' => 'localEmail', 'input' => $this->localEmail],
            ['field' => 'infraEmail', 'input' => $this->infraEmail],
            ['field' => 'stagingEmail', 'input' => $this->stagingEmail],
            ['field' => 'prodEmail', 'input' => $this->prodEmail],
        ]);

        // $confirm = $io->confirm('Are you happy with these settings? If you choose y the OU will be created with empty organisations, furthermore the needed IAM roles will be created', 'n');

        // if (!$confirm) {
        //     throw new Exception("User aborted, please run script again with the correct inputs");
        // }
    }

    public function execute(
        $ouName,
        $securityEmail
    ) {

        $this->createOrganization('Security', $securityEmail);

        return 0;
    }

    private function createOrganization($name, $email)
    {
        $client = $client = $this->getOrganizationClient();
        $createAccount = $client->createAccount([
            'AccountName' => $name,
            'Email' => $email,
        ]);
        $result = $client->describeCreateAccountStatus([
            'CreateAccountRequestId' => $createAccount['CreateAccountStatus']['Id'], 
        ]);

        while($result['CreateAccountStatus']['State'] == 'IN_PROGRESS') {
            sleep(5);
            $result = $client->describeCreateAccountStatus([
                'CreateAccountRequestId' => $createAccount['CreateAccountStatus']['Id'], 
            ]);
        }

        // waiting for quota increase from aws
        var_dump($result);
    }
    
    private function getAwsOrganizations()
    {
        $client = $this->getOrganizationClient();
        $results = $client->listAccounts([
            'MaxResults' => 20,
        ]);
        $awsOrganisations = $results['Accounts'];
        while ($nextToken = $results['NextToken']) {
            $results = $client->listAccounts([
                'MaxResults' => 20,
                'NextToken' => $nextToken,
            ]);
            $awsOrganisations = array_merge($awsOrganisations, $results['Accounts']);
        }
        return $awsOrganisations;
    }

    private function getOrganizationClient()
    {
        return new OrganizationsClient([
            'credentials' => [
                "key" => $this->awsAccessKeyId,
                "secret" => $this->awsAccessKeySecret,
            ],
            'region' => $_ENV["AWS_DEFAULT_REGION"],
            'version' => '2016-11-28'
        ]);
    }

}
