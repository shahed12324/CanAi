import { Redirect } from 'expo-router';
import { Loading } from '@/components/ui';
import { useSession } from '@/state/session';

export default function Index() {
  const { status } = useSession();
  if (status === 'loading') return <Loading />;
  return <Redirect href={status === 'in' ? '/home' : '/login'} />;
}
