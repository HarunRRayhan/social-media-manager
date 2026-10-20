import { Form, router, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Plus } from 'lucide-react';
import { useState } from 'react';
import TeamController from '@/actions/App/Http/Controllers/Team/TeamController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { update as switchTeam } from '@/routes/current-team';

export function TeamSwitcher() {
    const { auth } = usePage().props;
    const [creating, setCreating] = useState(false);

    if (!auth.currentTeam) {
        return null;
    }

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-8 max-w-48 gap-2 px-2 sm:max-w-64"
                        data-test="team-switcher"
                    >
                        <span className="flex size-6 shrink-0 items-center justify-center rounded-md border text-xs font-semibold uppercase">
                            {auth.currentTeam.name.charAt(0)}
                        </span>
                        <span className="truncate font-semibold">
                            {auth.currentTeam.name}
                        </span>
                        <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    className="min-w-56 rounded-lg"
                    align="start"
                >
                    <DropdownMenuLabel className="text-xs text-muted-foreground">
                        Teams
                    </DropdownMenuLabel>
                    {auth.teams.map((team) => (
                        <DropdownMenuItem
                            key={team.id}
                            className="gap-2"
                            onSelect={() =>
                                team.id !== auth.currentTeam.id &&
                                router.put(switchTeam.url(), {
                                    team_id: team.id,
                                })
                            }
                        >
                            <span className="flex-1 truncate">{team.name}</span>
                            {team.id === auth.currentTeam.id && (
                                <Check className="size-4" />
                            )}
                        </DropdownMenuItem>
                    ))}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        className="gap-2"
                        onSelect={() => setCreating(true)}
                    >
                        <Plus className="size-4" />
                        Create team
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent>
                    <DialogTitle>Create team</DialogTitle>
                    <DialogDescription>
                        A new team starts with one social set and you as its
                        owner.
                    </DialogDescription>

                    <Form
                        {...TeamController.store.form()}
                        resetOnSuccess
                        onSuccess={() => setCreating(false)}
                        className="space-y-6"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="team-name">Name</Label>
                                    <Input
                                        id="team-name"
                                        name="name"
                                        required
                                        autoFocus
                                        placeholder="e.g. Client work"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button variant="secondary">
                                            Cancel
                                        </Button>
                                    </DialogClose>
                                    <Button disabled={processing}>
                                        Create team
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </>
    );
}
