amazon-s3-migrator for IPC
--------------------------

How to use
==========

s3cmd is already installed on inspirewp-qa-web-01. When running the configure command for your user, you'll need to set the proxy host and proxy port to be `proxyhost` and `8080`:

    s3cmd --configure

To copy the images from local to s3, do the following. You'll need to do it by month as there are way to many files otherwise for most sites we have:

    s3cmd put -r /nfs/wordpressdata/inspirewp/sites/2/2014/02/ s3://testbasket/wp-content/uploads/sites/2/2014/02/  --add-header="Expires:`date -u +"%a, %d %b %Y %H:%M:%S GMT" --date "10 Years"`" --add-header='Cache-Control:max-age=315360000, public'
    s3cmd setacl s3://testbasket/wp-content/uploads/sites/2/2014/02/ --acl-public --recursive


A better, quicker way to do this is using AWS CLI:

Currently on inspirewp-live-web-01 this is at `~/aws/aws`

Configure the account for AWS:

```
aws configure --profile qa-test
     accesskey
     secretkey
     eu-west-1
     <return>
```

Configure the proxy before running the commands. You need the http and https or AWS will throw the error "Not supported proxy scheme None":

```
export HTTP_PROXY=http://<proxy-host>:<proxy-port>
export HTTPS_PROXY=https://<proxy-host>:<proxy-port>
```

Test a copy using dryrun:

```
cd /nfs/wordpressdata/inspirewp
aws s3 cp sites/3/2011/03/Hertfordshire_county_flag.jpg s3://inspire-ipcmedia-com/inspirewp/qa/wp-content/uploads/sites/3/2011/03/Hertfordshire_county_flag.jpg --acl public-read --color on --profile qa-test --dryrun
```

Copy a folder to S3 *cross fingers*:

```
aws s3 cp sites/2/2007/ s3://inspire-ipcmedia-com/inspirewp/live/wp-content/uploads/sites/2/2007/ --recursive --acl public-read --color on --profile live --dryrun --quiet 
```

See which profiles exist for the aws cli:

```
cat ~/.aws/config
```
