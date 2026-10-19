import { Form, Head } from '@inertiajs/react';
import MetaConnectionController from '@/actions/App/Http/Controllers/Team/MetaConnectionController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/connections';

type Candidate = {
    id: string;
    name: string;
    username?: string | null;
    avatar_url: string | null;
    connected: boolean;
};

type Props = {
    pages: Candidate[];
    instagram: Candidate[];
};

function Group({
    title,
    field,
    candidates,
}: {
    title: string;
    field: 'facebook' | 'instagram';
    candidates: Candidate[];
}) {
    return (
        <section className="space-y-3">
            <h2 className="text-sm font-medium">{title}</h2>

            {candidates.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    None were shared.
                </p>
            ) : (
                <div className="overflow-hidden rounded-lg border border-border">
                    {candidates.map((candidate) => {
                        const id = `${field}-${candidate.id}`;

                        return (
                            <Label
                                key={candidate.id}
                                htmlFor={id}
                                className="flex cursor-pointer items-center gap-4 border-b p-4 font-normal last:border-b-0"
                            >
                                <Checkbox
                                    id={id}
                                    name={`${field}[]`}
                                    value={candidate.id}
                                    defaultChecked={candidate.connected}
                                />
                                <Avatar className="h-10 w-10">
                                    <AvatarImage
                                        src={candidate.avatar_url ?? undefined}
                                        alt=""
                                    />
                                    <AvatarFallback>
                                        {candidate.name.charAt(0).toUpperCase()}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="space-y-1">
                                    <p className="font-medium tracking-tight">
                                        {candidate.name}
                                    </p>
                                    {candidate.username && (
                                        <p className="text-sm text-muted-foreground">
                                            @{candidate.username}
                                        </p>
                                    )}
                                </div>
                            </Label>
                        );
                    })}
                </div>
            )}
        </section>
    );
}

export default function ConnectionsPick({ pages, instagram }: Props) {
    return (
        <>
            <Head title="Choose accounts" />

            <h1 className="sr-only">Choose accounts</h1>

            <Form
                {...MetaConnectionController.store.form()}
                className="space-y-6"
            >
                {({ errors, processing }) => (
                    <>
                        <Heading
                            variant="small"
                            title="Choose accounts"
                            description="Pick the accounts the team should publish to. Accounts already connected stay connected; remove them on the Connections page."
                        />

                        <Group
                            title="Facebook Pages"
                            field="facebook"
                            candidates={pages}
                        />
                        <Group
                            title="Instagram"
                            field="instagram"
                            candidates={instagram}
                        />

                        <InputError message={Object.values(errors)[0]} />

                        <Button disabled={processing}>Connect selected</Button>
                    </>
                )}
            </Form>
        </>
    );
}

ConnectionsPick.layout = {
    breadcrumbs: [
        {
            title: 'Connections',
            href: index(),
        },
    ],
};
