import { Form, Head } from '@inertiajs/react';
import TeamController from '@/actions/App/Http/Controllers/Team/TeamController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/team';

type Props = {
    team: { id: number; name: string };
    canManage: boolean;
    canDelete: boolean;
    isOnlyTeam: boolean;
};

export default function General({
    team,
    canManage,
    canDelete,
    isOnlyTeam,
}: Props) {
    return (
        <>
            <Head title="Team settings" />

            <h1 className="sr-only">Team settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="General"
                    description="The team's name, shown in the team switcher"
                />

                <Form
                    {...TeamController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Team name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={team.name}
                                    required
                                    disabled={!canManage}
                                />
                                <InputError message={errors.name} />
                            </div>

                            {canManage && (
                                <Button disabled={processing}>Save</Button>
                            )}
                        </>
                    )}
                </Form>
            </div>

            {canDelete && (
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title="Delete team"
                        description="Delete the team with its social sets, posts and API tokens"
                    />
                    <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                        <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                            <p className="font-medium">Warning</p>
                            <p className="text-sm">
                                {isOnlyTeam
                                    ? 'This is your only team. Create another one before deleting it.'
                                    : 'Please proceed with caution, this cannot be undone.'}
                            </p>
                        </div>

                        <Dialog>
                            <DialogTrigger asChild>
                                <Button
                                    variant="destructive"
                                    disabled={isOnlyTeam}
                                >
                                    Delete team
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogTitle>Delete "{team.name}"?</DialogTitle>
                                <DialogDescription>
                                    Its social sets, posts and API tokens are
                                    deleted too, and every member loses access.
                                    You may be asked to confirm it's you first.
                                </DialogDescription>

                                <Form
                                    {...TeamController.destroy.form()}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <InputError message={errors.team} />
                                            <DialogFooter className="gap-2">
                                                <DialogClose asChild>
                                                    <Button variant="secondary">
                                                        Cancel
                                                    </Button>
                                                </DialogClose>
                                                <Button
                                                    variant="destructive"
                                                    disabled={processing}
                                                >
                                                    Delete team
                                                </Button>
                                            </DialogFooter>
                                        </>
                                    )}
                                </Form>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>
            )}
        </>
    );
}

General.layout = {
    breadcrumbs: [
        {
            title: 'Team settings',
            href: edit(),
        },
    ],
};
