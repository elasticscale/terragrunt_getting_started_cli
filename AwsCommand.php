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

        $io->write("First off, permissions..", true);

        $io->write('You need to create an AWS user & Access Keys with at least the following permissions:', true);
        $io->write('{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "ToManageOrganizations",
            "Effect": "Allow",
            "Action": [
                "organizations:CreateAccount",
                "organizations:DescribeCreateAccountStatus",
                "organizations:ListAccounts",
                "organizations:ListRoots",
                "organizations:ListOrganizationalUnitsForParent",
                "organizations:CreateOrganizationalUnit",
                "organizations:MoveAccount",
                "organizations:ListAccountsForParent"
            ],
            "Resource": "*"
        },
        {
            "Sid": "ToSwitchRolesIntoTheNewAccounts",
            "Effect": "Allow",
            "Action": [
                "sts:AssumeRole"                        
            ],
            "Resource": "*"
        }                
    ]
}', true);
        $io->write("This IAM user needs to be created in the root account and is temporary (for this script only)", true);

        if (!$this->awsAccessKeyId) {
            $io->write("Give the AWS Access Key (starts with AKIA)", true);
            $this->set('awsAccessKeyId', $io->prompt('Enter your AWS account key ID'));
        }
        if (!$this->awsAccessKeySecret) {
            $io->write("Give the AWS Access Key Secret", true);
            $this->set('awsAccessKeySecret', $io->prompt('Enter your AWS account key secret'));
        }
        if (!$this->modulePrefix) {
            $io->write("If you changed the prefix of the module to something else from the default (terragruntci)", true);
            $this->set('modulePrefix', $io->prompt('Enter your prefix', 'terragruntci'));
        }
        if (!$this->ouName) {
            $io->write("Choose the name of the new OU", true);
            $io->write("For example: Acme Systems Ltd", true);
            $this->set('ouName', $io->prompt('Enter the new OU name'));
        }

        try {
            $awsOrganizations = $this->getAwsOrganizations();
        } catch(Exception $e) {
            $io->boldRed("Could not get AWS Organizations so will not check if e-mail already exists", true);
            $awsOrganizations = [];
        }

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

        // foreach(["security", "local", "infra", "staging", "prod"] as $environment) {
        //     if (!$this->{$environment . "Email"}) {
        //         $io->write("Choose an email for the root user of the $environment account", true);
        //         $io->write("You can use aliasing here for example alex+$environment@elasticscale.cloud (provided your email provider supports it)", true);
        //         $this->set($environment . "Email", $io->prompt('Enter the email', null, $emailValidator));
        //     }
        // }

        $io->write("This is your input, is this correct?", true);

        $io->table([
            ['field' => 'awsAccessKeyId', $this->awsAccessKeyId],
            ['field' => 'modulePrefix', $this->modulePrefix],
            ['field' => 'ouName', 'input' => $this->ouName],
            ['field' => 'securityEmail', 'input' => $this->securityEmail],
            ['field' => 'localEmail', 'input' => $this->localEmail],
            ['field' => 'infraEmail', 'input' => $this->infraEmail],
            ['field' => 'stagingEmail', 'input' => $this->stagingEmail],
            ['field' => 'prodEmail', 'input' => $this->prodEmail],
        ]);

        $confirm = $io->confirm('Are you happy with these settings? If you choose y the OU will be created with empty organisations, furthermore the needed IAM roles will be created in the accounts', 'n');

        if (!$confirm) {
            throw new Exception("User aborted, please run script again with the correct inputs");
        }
    }

    public function execute(
        $ouName,
        $securityEmail,
        $infraEmail,
        $localEmail,
        $stagingEmail,
        $prodEmail
    ) {
        $io = $this->app()->io();

        try {
            $ouId = $this->createOu($ouName);
            if (!$ouId['ouId']) {
                throw new Exception("Could not create OU");
            }
        } catch(Exception $e) {
            $io->boldRed("Could not create OU account: " . $e->getMessage(), true);
            return 1;
        }

        try {
            $infrastructureAccountId = $this->createOrganization('Infrastructure', $infraEmail);
            if (!$infrastructureAccountId) {
                throw new Exception("Could not create infrastructure account");
            }
            $this->moveAccount($infrastructureAccountId, $ouId['rootId'], $ouId['ouId']);
            $this->createInfrastructureRole("infra", $infrastructureAccountId, $infrastructureAccountId);
            $io->boldGreen("Infrastructure account was created with ID: " + $infrastructureAccountId, true);
        } catch(Exception $e) {
            $io->boldRed("Could not create infrastructure account: " . $e->getMessage(), true);
            return 1;
        }

        try {
            $localAccountId = $this->createOrganization('Local', $localEmail);
            if (!$localAccountId) {
                throw new Exception("Could not create local account");
            }
            $this->moveAccount($localAccountId, $ouId['rootId'], $ouId['ouId']);
            // no need for the infra role here
            $io->boldGreen("Local account was created with ID: " + $localAccountId, true);
        } catch(Exception $e) {
            $io->boldRed("Could not create local account: " . $e->getMessage(), true);
            return 1;
        }

        try {
            $securityAccountId = $this->createOrganization('Security', $securityEmail);
            if (!$securityAccountId) {
                throw new Exception("Could not create security account");
            }
            $this->moveAccount($securityAccountId, $ouId['rootId'], $ouId['ouId']);
            $this->createInfrastructureRole("security", $infrastructureAccountId, $securityAccountId);
            $io->boldGreen("Security account was created with ID: " + $securityAccountId, true);
        } catch(Exception $e) {
            $io->boldRed("Could not create security account: " . $e->getMessage(), true);
            return 1;
        }

        try {
            $stagingAccountId = $this->createOrganization('Staging', $stagingEmail);
            if (!$stagingAccountId) {
                throw new Exception("Could not create staging account");
            }
            $this->moveAccount($stagingAccountId, $ouId['rootId'], $ouId['ouId']);
            $this->createInfrastructureRole("staging", $infrastructureAccountId, $stagingAccountId);
            $io->boldGreen("Staging account was created with ID: " + $stagingAccountId, true);
        } catch(Exception $e) {
            $io->boldRed("Could not create staging account: " . $e->getMessage(), true);
            return 1;
        }

        try {
            $productionAccountId = $this->createOrganization('Production', $prodEmail);
            if (!$productionAccountId) {
                throw new Exception("Could not create production account");
            }
            $this->moveAccount($productionAccountId, $ouId['rootId'], $ouId['ouId']);
            $this->createInfrastructureRole("prod", $infrastructureAccountId, $productionAccountId);
            $io->boldGreen("Production account was created with ID: " + $productionAccountId, true);
        } catch(Exception $e) {
            $io->boldRed("Could not create production account: " . $e->getMessage(), true);
            return 1;
        }

        $io->boldGreen("The accounts and roles were created, you can now delete the IAM user you used for this script", true);
        $io->boldGreen("You can now run this CLI script without any arguments to generate the repo, you need the account IDs for that (mentioned above)", true);

        return 0;
    }

    private function createInfrastructureRole($env, $infrastructureAccountId, $targetAccountId)
    {
        $io = $this->app()->io();
        $io->comment("Creating infrastructure role for $env", true);
        $stsClient = $this->getStsClient();
        $ARN = "arn:aws:iam::$targetAccountId:role/OrganizationAccountAccessRole";
        $sessionName = "create-infrastructure-role";
        $result = $stsClient->AssumeRole([
            'RoleArn' => $ARN,
            'RoleSessionName' => $sessionName,
        ]);
        $credentials = [
         'key'    => $result['Credentials']['AccessKeyId'],
         'secret' => $result['Credentials']['SecretAccessKey'],
         'token'  => $result['Credentials']['SessionToken']
        ];
        $iamClient = new Aws\Iam\IamClient([
             'credentials' => $credentials,
             'region' => 'us-east-2',
             'version' => '2010-05-08'
         ]);
        $roleName = "{$this->modulePrefix}-$env-role";
        $iamClient->createRole([
            'AssumeRolePolicyDocument' => json_encode([
                "Version" => "2012-10-17",
                "Statement" => [
                    [
                        "Effect" => "Allow",
                        "Action" => "sts:AssumeRole",
                        "Principal" => [
                            "AWS" => $infrastructureAccountId,
                        ]
                    ]
                ]
                    ]),
            'Description' => 'This role provisions the Terragrunt infrastructure',
            'RoleName' => $roleName,
        ]);
        $iamClient->attachRolePolicy([
            'PolicyArn' => "arn:aws:iam::aws:policy/AdministratorAccess",
            'RoleName' => $roleName,
        ]);
    }

    private function moveAccount($accountId, $fromId, $toId)
    {
        $io = $this->app()->io();
        $io->comment("Moving $accountId from $from to $toId", true);

        $client = $this->getOrganizationClient();
        $client->moveAccount([
            'AccountId' => $accountId,
            'SourceParentId' => $fromId,
            'DestinationParentId' => $toId,
        ]);
    }

    private function createOu($name)
    {
        $io = $this->app()->io();
        $io->comment("Creating $name OU", true);

        $client = $this->getOrganizationClient();
        $roots = $client->listRoots([
            'MaxResults' => 20,
        ]);
        $rootId = $roots["Roots"][0]["Id"] ?? null;
        if (!$rootId) {
            throw new Exception("Could not determine root ID");
        }
        $ou = $client->createOrganizationalUnit([
            'Name' => $name,
            'ParentId' => $rootId,
        ]);
        return [
            "ouId" => $ou['OrganizationalUnit']['Id'] ?? null,
            "rootId" => $rootId,
        ];
    }

    private function createOrganization($name, $email)
    {
        $io = $this->app()->io();
        $io->comment("Creating $name account", true);
        $client = $this->getOrganizationClient();
        $createAccount = $client->createAccount([
            'AccountName' => $name,
            'Email' => $email,
        ]);
        $result = $client->describeCreateAccountStatus([
            'CreateAccountRequestId' => $createAccount['CreateAccountStatus']['Id'],
        ]);
        while($result['CreateAccountStatus']['State'] == 'IN_PROGRESS') {
            $io->comment("* In progress", true);
            sleep(5);
            $result = $client->describeCreateAccountStatus([
                'CreateAccountRequestId' => $createAccount['CreateAccountStatus']['Id'],
            ]);
        }
        if($result['CreateAccountStatus']['State'] != 'SUCCEEDED') {
            throw new Exception("Could not create account: " . $result['CreateAccountStatus']['FailureReason']);
        }
        $io->greenBold("* Complete!", true);
        return $result['CreateAccountStatus']['AccountId'];
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

    private function getStsClient()
    {
        return new Aws\Sts\StsClient([
            'credentials' => [
                "key" => $this->awsAccessKeyId,
                "secret" => $this->awsAccessKeySecret,
            ],
            'region' => 'us-east-2',
            'version' => '2011-06-15'
        ]);
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
