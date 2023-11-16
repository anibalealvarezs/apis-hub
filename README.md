# apis-hub

## Instructions

1. Create the `config/yaml/dbconfig.yaml` file from the `config/yaml/dbconfig-example.yaml` file included in this repo and configure your database connection

2. Run the following command in order to create the table in the database

    ```bash
    vendor/bin/doctrine orm:schema-tool:update --force --complete
    ```

3. Execute the corresponding command in order to interact with jobs in the database

    ```bash
    php bin/cli.php app:{method}
    ```

    ### Available methods

   - `create`: Create a new job
   - `delete`: Delete an existing job
   - `read`: Read a new job (or a list of jobs)
   - `update`: Update an existing job

    ### Methods' options

   - Create (aliases: `create`, `new`):
       - `--entity`: The name of the entity (which is: "Job")
       - `--data`: The data for the job record to be created  
         Example:

    ```bash
    php bin/cli.php app:create --entity='Job' --data='{"filename":"asdfghjklqwertyuiop","status":"processing"}'
    ```

   - Delete (aliases: `delete`, `remove`):
       - `--entity`: The name of entity (which is: "Job")
       - `--id`: The id of the job record to be deleted  
         Example:

    ```bash
    php bin/cli.php app:delete --entity='Job' --id='758'
    ```

   - Read (aliases: `read`, `get`):
       - `--entity`: The name of the entity (which is: "Job")
       - `--id`: The id of the jobs to be retrieved (optional)
       - `--filters`: The filters to be applied to the query (optional - ignored if `--id` is set)  
         Examples:

    ```bash
    php bin/cli.php app:read --entity='Job'
    ```

    ```bash
    php bin/cli.php app:read --entity='Job' --id='758'
    ```
    
    ```bash
    php bin/cli.php app:read --entity='Job' --filters='{"filename":"asdfghjklqwertyuiop"}'
    ```

   - Update (aliases: `update`):
       - `--entity`: The name of the entity (which is: "Job")
       - `--id`: The id of the jobs to be retrieved
       - `--data`: The data to be updated in the job record  
         Example:

    ```bash
    php bin/cli.php app:update --entity='Job' --id='758' --data='{"status":"completed"}'
    ```

    ### Currently supported entities

   - `jobs`

## Testing

Run benchmarks for QueryBuilder and DQL queries:

```bash
./vendor/bin/phpbench run tests/Benchmark --report='default'
```
