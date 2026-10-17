import { Head } from '@inertiajs/react';
import { edit } from '@/routes/security';
import type { Props } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';

export default function Security(props: Props) {
    return (
        <>
            <Head title="Security settings" />

            <h1 className="sr-only">Security settings</h1>

            <ManagePasskeys
                canManagePasskeys={props.canManagePasskeys}
                passkeys={props.passkeys}
            />
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'Security settings',
            href: edit(),
        },
    ],
};
