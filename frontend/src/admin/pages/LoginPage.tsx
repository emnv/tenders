import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import ocnLogo from '@/assets/ocn-logo.png'
import { adminFetch, setAdminToken } from '../lib/adminFetch'

export default function LoginPage() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [isLoading, setIsLoading] = useState(false)
  const navigate = useNavigate()

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setIsLoading(true)
    setError(null)

    try {
      const response = await adminFetch('/admin/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
      })

      if (!response.ok) {
        throw new Error('Invalid credentials')
      }

      const payload = await response.json()
      const token = payload?.data?.token
      if (!token) {
        throw new Error('Missing token')
      }

      setAdminToken(token)
      navigate('/admin', { replace: true })
    } catch {
      setError('Login failed. Check your credentials.')
    } finally {
      setIsLoading(false)
    }
  }

  return (
    <section className="flex h-screen w-screen items-center justify-center overflow-hidden bg-muted/40">
      <div className="flex w-full max-w-md flex-col gap-4">
        <div className="flex flex-col items-center gap-3 text-center">
          <img src={ocnLogo} alt="Ontario Construction News" className="h-16 w-auto" />
        </div>
        <Card>
          <CardHeader className="text-center">
            <CardTitle className="text-xl">Welcome back</CardTitle>
            <CardDescription>Sign in to manage OCN tenders.</CardDescription>
          </CardHeader>
          <CardContent>
            {error && (
              <div className="mb-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-600">
                {error}
              </div>
            )}
            <form onSubmit={handleSubmit} className="grid gap-6">
              <div className="grid gap-4">
                <div className="grid gap-2">
                  <Label htmlFor="email">Email</Label>
                  <Input
                    id="email"
                    type="email"
                    required
                    value={email}
                    onChange={(event) => setEmail(event.target.value)}
                    placeholder="admin@ocn.local"
                  />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="password">Password</Label>
                  <Input
                    id="password"
                    type="password"
                    required
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                  />
                </div>
                <Button type="submit" className="w-full" disabled={isLoading}>
                  {isLoading ? 'Signing in...' : 'Login'}
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
        <p className="text-balance text-center text-xs text-muted-foreground">
          By continuing, you agree to the OCN admin usage policy.
        </p>
      </div>
    </section>
  )
}
