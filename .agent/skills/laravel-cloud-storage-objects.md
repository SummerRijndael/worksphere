> ## Documentation Index
>
> Fetch the complete documentation index at: https://cloud.laravel.com/docs/llms.txt
> Use this file to discover all available pages before exploring further.

# Laravel Object Storage

> S3-compatible object storage buckets

<Frame>
  <Icon icon="handshake" size="20" /> Powered by [Cloudflare R2](https://www.cloudflare.com/developer-platform/products/r2/)
</Frame>

## Introduction

Laravel Cloud allows you to create S3-compatible object storage buckets and attach them to your application's environments directly from the Laravel Cloud dashboard. Laravel Cloud Object Storage is offered in partnership with Cloudflare R2.

Laravel Cloud Object Storage may be used as your Laravel application's [file storage backend](https://laravel.com/docs/filesystem), allowing you to interact with the bucket via Laravel's `Storage` facade.

## Prerequisites

Before utilizing Laravel Object Storage, you should ensure your application includes the `league/flysystem-aws-s3-v3` package in its `composer.json` dependencies:

```shell theme={null}
composer require league/flysystem-aws-s3-v3 "^3.0" --with-all-dependencies
```

## Creating buckets

<Frame>
    <img src="https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/add-bucket.png?fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=f95683cb5995fb4e96966d6b9fb34848" alt="" data-og-width="1209" width="1209" data-og-height="474" height="474" data-path="images/add-bucket.png" data-optimize="true" data-opv="3" srcset="https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/add-bucket.png?w=280&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=3e9c160fb3d885eade0f132899bb721b 280w, https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/add-bucket.png?w=560&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=1ce83049da1a204b2c30ac53ae6e9ef8 560w, https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/add-bucket.png?w=840&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=af44b591e17cefd1c5b28cac98508219 840w, https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/add-bucket.png?w=1100&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=0bd4973f98b2c391dbce2e76d699a88b 1100w, https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/add-bucket.png?w=1650&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=b66233276b3f79625c7dba253fd03c53 1650w, https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/add-bucket.png?w=2500&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=b9430a16180ba6230df9ffb52609fe63 2500w" />
</Frame>

To attach a Laravel Object Storage bucket to an environment, click "Add bucket" on your environment's infrastructure canvas dashboard. When adding a bucket to an environment, Laravel Cloud will prompt you to select the bucket you would like to attach to the environment or to create a new bucket.

When creating a new bucket, select "Laravel Object Storage" as your bucket type.

Once the bucket has been attached to an environment, you will need to re-deploy the environment in order for the changes to take effect.

Visit the [pricing](/pricing#laravel-object-storage) docs for information on compute and storage prices.

### Bucket disk names

When creating a bucket, you will also be prompted to provide a "disk name". This name corresponds to the name you will use when accessing the bucket / disk via Laravel's `Storage` facade:

```php theme={null}
return Storage::disk('r2')->get('photo.jpg');
```

You will also be able to indicate if the disk should be the "default" disk for the Laravel application. When a bucket is the default disk, you do not need to provide its name when accessing it via Laravel's `Storage` facade:

```php theme={null}
return Storage::get('photo.jpg');
```

### Bucket file visibility

When creating a bucket, you will be prompted to select the bucket's file visibility. All files added to the bucket will receive the specified visibility, and Laravel Cloud Object Storage buckets do not support mixing file visibility settings within a single bucket.

- **Private buckets:** all files within the bucket are private and are not publicly accessible via the Internet. However, temporary public URLs may be generated to files within these buckets using the `Storage::temporaryUrl` method [offered by Laravel](https://laravel.com/docs/filesystem#temporary-urls). These buckets are typically used for private assets like personal documents uploaded by your application's users.
- **Public buckets:** all files within the bucket are public and are publicly accessible via the Internet via a Laravel Cloud provided URL. These buckets are typically used for publicly viewable assets like user avatars.

### CORS policy

Laravel Cloud automatically manages CORS (Cross-Origin Resource Sharing) policies for your object storage buckets to ensure browsers will permit cross-origin requests.

#### Automatic domain inclusion

When you attach a bucket to an environment, all of the environment's custom and Cloud domains are automatically included in the bucket's CORS policy. This happens automatically without any configuration required on your part.

#### Custom allowed origins

In addition to your environment's domains, you may specify additional origins that should be allowed to access your bucket. This is particularly useful for:

- Local development environments (e.g., `http://example.test`)
- External domains that need access to your bucket
- Testing from non-production environments

To add custom allowed origins:

1. Navigate to your organization's resources page by clicking **Org > Resources > Object storage** from the main dashboard.
2. Click the "**...**" menu next to the bucket you want to configure.
3. Select "**Edit settings**".
4. In the "**Allowed origins**" field (only visible for public buckets), enter the origins you want to allow, separated by new lines.
5. Each origin must be prefixed with the protocol (`https://` or `http://`).

<Frame>
    <img src="https://mintcdn.com/cloud/XVjqrUGXGJBWPsZ0/images/resources/object-origins.png?fit=max&auto=format&n=XVjqrUGXGJBWPsZ0&q=85&s=86c30893a2acc129420eda21358e955f" alt="" data-og-width="976" width="976" data-og-height="552" height="552" data-path="images/resources/object-origins.png" data-optimize="true" data-opv="3" srcset="https://mintcdn.com/cloud/XVjqrUGXGJBWPsZ0/images/resources/object-origins.png?w=280&fit=max&auto=format&n=XVjqrUGXGJBWPsZ0&q=85&s=cb775397a3885ee139b3c1940dbbc9e1 280w, https://mintcdn.com/cloud/XVjqrUGXGJBWPsZ0/images/resources/object-origins.png?w=560&fit=max&auto=format&n=XVjqrUGXGJBWPsZ0&q=85&s=562f986f79b14349516beffdc161876e 560w, https://mintcdn.com/cloud/XVjqrUGXGJBWPsZ0/images/resources/object-origins.png?w=840&fit=max&auto=format&n=XVjqrUGXGJBWPsZ0&q=85&s=ecf20f74a165e903817b0641678b0485 840w, https://mintcdn.com/cloud/XVjqrUGXGJBWPsZ0/images/resources/object-origins.png?w=1100&fit=max&auto=format&n=XVjqrUGXGJBWPsZ0&q=85&s=96b6ccf13ec0615d8b0978c383eaf0b6 1100w, https://mintcdn.com/cloud/XVjqrUGXGJBWPsZ0/images/resources/object-origins.png?w=1650&fit=max&auto=format&n=XVjqrUGXGJBWPsZ0&q=85&s=74ec3e507ab39ddadaa2611c18e935a6 1650w, https://mintcdn.com/cloud/XVjqrUGXGJBWPsZ0/images/resources/object-origins.png?w=2500&fit=max&auto=format&n=XVjqrUGXGJBWPsZ0&q=85&s=4037831f6c5f5c584fc28d2efd141aef 2500w" />
</Frame>

#### Local development

To connect to your object storage buckets from your local Laravel application, add your local development domain (e.g., `http://example.test`) to the bucket's allowed origins. This makes it easy to test file uploads and storage operations during development.

#### Technical details

The CORS policy applied to your buckets allows the following:

- **Methods:** GET, POST, PUT, DELETE, HEAD
- **Headers:** All headers (`*`) are permitted
- **Origins:** All environment domains plus any custom origins you've configured

## Connecting to buckets

### From your application

When a bucket is attached to an environment, Laravel Cloud will automatically inject the environment variables needed by the Laravel application to interact with the bucket via the `Storage` facade, including the `FILESYSTEM_DISK` and AWS S3-compatible bucket related variables. You may view these environment variables in your environment's General Settings.

### From your local machine

To connect to your bucket from your local machine using a Cloudflare R2 compatible bucket management client like [Cyberduck](https://cyberduck.io/):

1. Navigate to your bucket by going to `Resources > Object storage` from the main dashboard. Then click the "..." icon next to the bucket and then click "View credentials".

<Frame>
    <img src="https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/bucket-credentials.png?fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=b2905ff32df7a65be06cfa17d8b94a22" alt="" data-og-width="846" width="846" data-og-height="219" height="219" data-path="images/bucket-credentials.png" data-optimize="true" data-opv="3" srcset="https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/bucket-credentials.png?w=280&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=2a77e36cd3e5e148eeb9e7be14fa193e 280w, https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/bucket-credentials.png?w=560&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=ab70859669156c0b1486d677483c056a 560w, https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/bucket-credentials.png?w=840&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=c2e48750d99125117bef3a7760e7fa9b 840w, https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/bucket-credentials.png?w=1100&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=cac43679dcaabdeee397d85b0545cb29 1100w, https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/bucket-credentials.png?w=1650&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=2f1a4a05d98cdd21c8f2186414b4c303 1650w, https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/bucket-credentials.png?w=2500&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=53b3e4dbd72ad7dc0a8e71383bdf7a4d 2500w" />
</Frame>

2. The bucket credentials modal window will provide you with the name, endpoint, access key ID, and access key secret needed to connect to your bucket.

<Frame>
    <img src="https://mintcdn.com/cloud/yTBfOwyROZUdb0EG/images/resources/object-storage-credentials.png?fit=max&auto=format&n=yTBfOwyROZUdb0EG&q=85&s=266fb36f200be467be7d888180679cd8" alt="" data-og-width="1064" width="1064" data-og-height="708" height="708" data-path="images/resources/object-storage-credentials.png" data-optimize="true" data-opv="3" srcset="https://mintcdn.com/cloud/yTBfOwyROZUdb0EG/images/resources/object-storage-credentials.png?w=280&fit=max&auto=format&n=yTBfOwyROZUdb0EG&q=85&s=3bc23783e30fa39a811a55b23c612c46 280w, https://mintcdn.com/cloud/yTBfOwyROZUdb0EG/images/resources/object-storage-credentials.png?w=560&fit=max&auto=format&n=yTBfOwyROZUdb0EG&q=85&s=51f776844c0ff4ce4c42583b60de097d 560w, https://mintcdn.com/cloud/yTBfOwyROZUdb0EG/images/resources/object-storage-credentials.png?w=840&fit=max&auto=format&n=yTBfOwyROZUdb0EG&q=85&s=7e68f0f35824c72ea40cd87229dafff1 840w, https://mintcdn.com/cloud/yTBfOwyROZUdb0EG/images/resources/object-storage-credentials.png?w=1100&fit=max&auto=format&n=yTBfOwyROZUdb0EG&q=85&s=cc60ff744c0ed7b6eea88dfecfbc1de4 1100w, https://mintcdn.com/cloud/yTBfOwyROZUdb0EG/images/resources/object-storage-credentials.png?w=1650&fit=max&auto=format&n=yTBfOwyROZUdb0EG&q=85&s=8c878a806e4406e6345e476a1f59527f 1650w, https://mintcdn.com/cloud/yTBfOwyROZUdb0EG/images/resources/object-storage-credentials.png?w=2500&fit=max&auto=format&n=yTBfOwyROZUdb0EG&q=85&s=9cbf7e6ad5d6e5a2d44d8e875af6b224 2500w" />
</Frame>

3. When connecting to the bucket via Cyberduck:
    - Open Cyberduck, select "+" in the bottom left-hand corner to create a new bookmark, and select "Cloudflare R2 Storage (S3)" as the connection type. If this is your first time using the Cloudflare R2 Storage connection, you may need to add it from `Settings > Profiles`. [Learn more](https://docs.cyberduck.io/protocols/s3/cloudflare/)
    - Enter the "AWS_ENDPOINT" bucket credentials value into the Cyberduck "Server" field
    - Enter the "AWS_ACCESS_KEY_ID" bucket credentials value into the Cyberduck "Access Key ID" field
    - Enter the "AWS_SECRET_ACCESS_KEY" bucket credentials value into the Cyberduck "Access Key Secret" field
    - Enter the "AWS_BUCKET" value into the Cyberduck "Path" field (under "More Options").
        - If you do not see "More Options", you likely clicked "Open Connection" instead of creating a new bookmark from the first step.
    - Optional: Give your Bookmark a "Nickname"
4. Your new Cyberduck connection should look like this when completed. You can now close the "Open connection" modal and connect to your bucket.

<Frame>
    <img src="https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/cyberduck-bookmark.png?fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=a78db93d20f02e1cde31649a39b70529" alt="" data-og-width="1168" width="1168" data-og-height="1134" height="1134" data-path="images/cyberduck-bookmark.png" data-optimize="true" data-opv="3" srcset="https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/cyberduck-bookmark.png?w=280&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=2aab9b2c37bcdd469a6f1a68b5694f31 280w, https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/cyberduck-bookmark.png?w=560&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=37aa234cdb8b27622b3da02ca96bcf61 560w, https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/cyberduck-bookmark.png?w=840&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=f10f9807865316cba96724e46c8fcfd4 840w, https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/cyberduck-bookmark.png?w=1100&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=7b998801af0b7577c3f136acf2b6b54a 1100w, https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/cyberduck-bookmark.png?w=1650&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=4b4601965d9e76fe54fe5b0f79ffde32 1650w, https://mintcdn.com/cloud/UOLAxYMuoaqWUDDG/images/cyberduck-bookmark.png?w=2500&fit=max&auto=format&n=UOLAxYMuoaqWUDDG&q=85&s=5b950b2dca19f05656831bf9f69bcd45 2500w" />
</Frame>

## Editing buckets

You may edit Laravel Object Storage buckets via your organization's "Resources" page. From the "Resources" page, navigate to the "Object storage" tab and click the "..." icon for the bucket you would like to edit. Then, click "Edit settings".

## Deleting buckets

You may delete a Laravel Object Storage bucket via your organization's "Resources" page. From the "Resources" page, navigate to the "Object storage" tab and click the "..." icon for the bucket you would like to delete. Then, click "Delete bucket".
